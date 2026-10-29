<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerTier;
use App\Models\Product;
use App\Services\DealerPriceImportParser;
use App\Services\DealerPriceImportService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerPriceImportController extends Controller
{
    public function preview(
        Product $product,
        Request $request,
        DealerPriceImportParser $parser,
        DealerPriceImportService $imports,
    ): JsonResponse {
        $this->assertAvailable($product);
        $data = $request->validate([
            'source' => ['required', 'in:paste,file'],
            'paste' => ['required_if:source,paste', 'nullable', 'string', 'max:30000'],
            'file' => ['required_if:source,file', 'nullable', 'file', 'max:5120'],
        ]);
        $source = $data['source'] === 'paste'
            ? $parser->paste($data['paste'])
            : $parser->file($request->file('file'));
        $rows = $imports->preview($product, $source);
        $canApply = ! collect($rows)->contains(fn (array $row): bool => $row['errors'] !== []);
        $previewToken = $canApply ? Crypt::encryptString(json_encode([
            'product_id' => $product->id,
            'user_id' => $request->user()->id,
            'source' => $source,
            'expires_at' => now()->addMinutes(10)->timestamp,
        ], JSON_THROW_ON_ERROR)) : null;

        return response()->json(['rows' => $rows, 'can_apply' => $canApply, 'preview_token' => $previewToken]);
    }

    public function apply(Product $product, Request $request, DealerPriceImportService $imports): JsonResponse
    {
        $this->assertAvailable($product);
        $token = $request->validate(['preview_token' => ['required', 'string', 'max:150000']])['preview_token'];
        try {
            $preview = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            throw ValidationException::withMessages(['preview_token' => 'Bản xem trước không hợp lệ.']);
        }
        if (! is_array($preview)
            || ($preview['product_id'] ?? null) !== $product->id
            || ($preview['user_id'] ?? null) !== $request->user()->id
            || ($preview['expires_at'] ?? 0) < now()->timestamp
            || ! is_array($preview['source'] ?? null)) {
            throw ValidationException::withMessages(['preview_token' => 'Vui lòng xem trước dữ liệu trước khi áp dụng.']);
        }
        if (! Cache::add('dealer-price-import:'.hash('sha256', $token), true, now()->addMinutes(10))) {
            throw ValidationException::withMessages(['preview_token' => 'Bản xem trước đã được áp dụng. Vui lòng xem trước lại.']);
        }

        $count = $imports->apply($product, $preview['source'], $request->user()->id);

        return response()->json(['applied_count' => $count]);
    }

    public function template(Product $product): StreamedResponse
    {
        $this->assertAvailable($product);
        $tiers = DealerTier::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get(['code']);
        $skus = $product->variants()->orderBy('id')->pluck('sku');

        return response()->streamDownload(function () use ($tiers, $skus): void {
            $output = fopen('php://output', 'wb');
            fputcsv($output, ['Tier', 'SKU', 'MOQ', 'Price']);
            foreach ($tiers as $tier) {
                foreach ($skus as $sku) {
                    fputcsv($output, [$tier->code, $sku, '1', '']);
                }
            }
            fclose($output);
        }, 'mau_gia_dai_ly_'.$product->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function assertAvailable(Product $product): void
    {
        abort_if($product->status === 'draft' && $product->wizard_key !== null, 404);
    }
}
