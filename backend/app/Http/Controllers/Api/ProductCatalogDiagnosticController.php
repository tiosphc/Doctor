<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ProductCatalogDiagnosticStage;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Throwable;

class ProductCatalogDiagnosticController extends Controller
{
    private const TABLES = [
        'products', 'product_variants', 'product_categories', 'brands',
        'price_lists', 'price_list_items', 'sales_promotions', 'sales_promotion_targets',
        'sales_promotion_redemptions', 'sales_promotion_gift_rules', 'product_images',
        'units', 'migrations', 'warehouses', 'inventory_balances',
        'dealer_tiers', 'sales_promotion_dealer_tiers',
    ];

    public function __construct(private readonly ProductCatalogController $catalog) {}

    public function __invoke(Request $request): JsonResponse
    {
        $configuredKey = config('diagnostics.products_key');
        if (! is_string($configuredKey) || $configuredKey === '') {
            abort(404);
        }

        $providedKey = $request->header('X-Diagnostic-Key');
        if (! is_string($providedKey) || ! hash_equals($configuredKey, $providedKey)) {
            abort(403);
        }

        ProductCatalogDiagnosticStage::start($request);

        try {
            $catalog = $this->catalog->index($request);
            $payload = $catalog->response($request)->getData(true);

            return response()->json([
                'ok' => true,
                'stage' => 'resource',
                'product_count' => count($payload['data'] ?? []),
                'table_checks' => $this->tableChecks(),
                'extensions' => ['bcmath' => extension_loaded('bcmath')],
            ], headers: ['Cache-Control' => 'no-store']);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'stage' => $this->failedStage($request, $exception),
                'exception_class' => $exception::class,
                'message' => $this->safeMessage($exception),
                'sql_state' => $this->sqlState($exception),
                'source' => basename($exception->getFile()).':'.$exception->getLine(),
                'table_checks' => $this->tableChecks(),
                'extensions' => ['bcmath' => extension_loaded('bcmath')],
            ], 500, ['Cache-Control' => 'no-store']);
        }
    }

    /** @return array<string, bool|null> */
    private function tableChecks(): array
    {
        $tables = [];

        foreach (self::TABLES as $table) {
            try {
                $tables[$table] = Schema::hasTable($table);
            } catch (Throwable $exception) {
                report($exception);
                $tables[$table] = null;
            }
        }

        return $tables;
    }

    private function failedStage(Request $request, Throwable $exception): string
    {
        $stage = ProductCatalogDiagnosticStage::current($request);
        if ($stage === 'relationship' && $exception instanceof QueryException) {
            $sql = strtolower($exception->getSql());
            if (str_contains($sql, 'from `products`') || str_contains($sql, 'from "products"')
                || str_contains($sql, 'from products')) {
                return 'query';
            }
        }

        return $stage;
    }

    private function safeMessage(Throwable $exception): string
    {
        if ($exception instanceof QueryException) {
            $driverMessage = (string) ($exception->errorInfo[2] ?? '');
            if (preg_match("/Table '([A-Za-z0-9_.]+)' doesn't exist/i", $driverMessage, $matches) === 1) {
                return 'Missing table: '.$matches[1];
            }
            if (preg_match("/Unknown column '([A-Za-z0-9_.]+)'/i", $driverMessage, $matches) === 1) {
                return 'Unknown column: '.$matches[1];
            }

            return 'Database query failed.';
        }

        if ($exception instanceof HttpResponseException) {
            $code = $exception->getResponse()->getData(true)['code'] ?? null;
            if (in_array($code, ['PRICE_NOT_FOUND', 'PRICE_AMBIGUOUS'], true)) {
                return $code;
            }
        }

        $message = $exception->getMessage();
        if (str_starts_with($message, 'Call to undefined function ')
            && str_contains($message, 'bc')) {
            return 'A required PHP BCMath function is unavailable.';
        }

        return 'Product catalog execution failed.';
    }

    private function sqlState(Throwable $exception): ?string
    {
        if (! $exception instanceof QueryException) {
            return null;
        }

        $state = $exception->errorInfo[0] ?? null;

        return is_string($state) && preg_match('/^[A-Z0-9]{5}$/', $state) === 1 ? $state : null;
    }
}
