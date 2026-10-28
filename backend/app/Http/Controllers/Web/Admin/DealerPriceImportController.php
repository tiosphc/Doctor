<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Models\DealerTier;
use App\Models\Product;
use App\Services\DealerPriceImportParser;
use App\Services\DealerPriceImportService;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DealerPriceImportController extends Controller
{
    public function show(Product $product, Request $request): View
    {
        $this->assertAvailable($product);
        $preview = $request->session()->get('dealer_price_import_preview');

        return view('admin.prices.import', [
            'product' => $product,
            'preview' => ($preview['product_id'] ?? null) === $product->id ? $preview['rows'] : null,
            'tiers' => DealerTier::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name']),
            'variants' => $product->variants()->orderBy('id')->get(['id', 'sku', 'variant_name']),
        ]);
    }

    public function preview(Product $product, Request $request, DealerPriceImportParser $parser, DealerPriceImportService $imports): RedirectResponse
    {
        $this->assertAvailable($product);
        $request->session()->forget('dealer_price_import_preview');
        $data = $request->validate([
            'source' => ['required', 'in:paste,file'],
            'paste' => ['required_if:source,paste', 'nullable', 'string', 'max:30000'],
            'file' => ['required_if:source,file', 'nullable', 'file', 'max:5120'],
        ]);
        $source = $data['source'] === 'paste' ? $parser->paste($data['paste']) : $parser->file($request->file('file'));
        $rows = $imports->preview($product, $source);
        $request->session()->put('dealer_price_import_preview', ['product_id' => $product->id, 'source' => $source, 'rows' => $rows]);

        return redirect()->route('admin.web.prices.dealer.import.show', $product);
    }

    public function apply(Product $product, Request $request, DealerPriceImportService $imports): RedirectResponse
    {
        $this->assertAvailable($product);
        $preview = $request->session()->get('dealer_price_import_preview');
        if (($preview['product_id'] ?? null) !== $product->id || ! is_array($preview['source'] ?? null)) {
            return back()->withErrors(['import' => 'Vui lòng xem trước dữ liệu trước khi áp dụng.']);
        }
        if (collect($preview['rows'])->contains(fn (array $row): bool => $row['errors'] !== [])) {
            return back()->withErrors(['import' => 'Cần sửa mọi dòng lỗi trước khi áp dụng.']);
        }
        try {
            $count = $imports->apply($product, $preview['source'], $request->user()->id);
        } catch (HttpResponseException $exception) {
            return back()->withErrors(['import' => $exception->getResponse()->getData(true)['code'] ?? 'Không thể import giá.']);
        }
        $request->session()->forget('dealer_price_import_preview');

        return redirect()->route('admin.web.prices.dealer.index', ['product_id' => $product->id])
            ->with('success', 'Đã áp dụng '.$count.' dòng giá đại lý.');
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
