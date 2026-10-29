<?php

namespace App\Services;

use App\Models\DealerTier;
use App\Models\PriceListItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Support\Sku;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DealerPriceImportService
{
    public function __construct(private readonly ProductPricingService $pricing) {}

    /** @param list<array<string, mixed>> $source @return list<array<string, mixed>> */
    public function preview(Product $product, array $source): array
    {
        if ($source === [] || count($source) > 60) {
            throw ValidationException::withMessages(['import' => 'Cần từ 1 đến 60 dòng giá.']);
        }
        $variants = $product->variants()->get(['id', 'product_id', 'sku'])->keyBy(fn (ProductVariant $variant): string => Sku::normalize($variant->sku));
        $tiers = DealerTier::query()->where('status', 'active')->orderBy('sort_order')->orderBy('id')->get(['id', 'code', 'name']);
        $at = now()->toDateTimeString();
        $current = PriceListItem::query()->with('priceList')->whereIn('product_variant_id', $variants->pluck('id'))
            ->where('status', 'active')
            ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at))
            ->whereHas('priceList', fn ($query) => $query->where('pricing_context', 'dealer')->where('scope_type', 'tier')
                ->where('status', 'active')->where('currency', 'VND')
                ->where(fn ($query) => $query->whereNull('effective_from')->orWhere('effective_from', '<=', $at))
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $at)))
            ->get()->mapWithKeys(fn (PriceListItem $item): array => [$item->priceList->dealer_tier_id.':'.$item->product_variant_id => true]);
        $seen = [];
        $result = [];
        foreach ($source as $row) {
            $errors = [];
            $tierName = mb_strtolower(trim((string) ($row['tier'] ?? '')));
            $tier = $tiers->first(fn (DealerTier $candidate): bool => mb_strtolower($candidate->code) === $tierName || mb_strtolower($candidate->name) === $tierName);
            $sku = Sku::normalize((string) ($row['sku'] ?? ''));
            $variant = $variants->get($sku);
            $moq = trim((string) ($row['moq'] ?? ''));
            $price = trim((string) ($row['price'] ?? ''));
            if ($row['format_error'] ?? null) {
                $errors[] = $row['format_error'];
            }
            if ($tier === null) {
                $errors[] = 'Tier không tồn tại hoặc không hoạt động.';
            }
            if ($variant === null) {
                $errors[] = 'SKU không thuộc sản phẩm này.';
            }
            if (preg_match('/^[1-9]\d*$/', $moq) !== 1 || filter_var($moq, FILTER_VALIDATE_INT) === false) {
                $errors[] = 'MOQ phải là số nguyên từ 1 trở lên.';
            }
            if (preg_match('/^(?:0|[1-9]\d{0,15})(?:\.\d{1,2})?$/', $price) !== 1 || bccomp($price, '0', 2) <= 0) {
                $errors[] = 'Giá phải lớn hơn 0, tối đa 2 chữ số thập phân.';
            }
            if ($tier !== null && $variant !== null) {
                $key = $tier->id.':'.$variant->id;
                if (isset($seen[$key])) {
                    $errors[] = 'Trùng Tier và SKU trong dữ liệu import.';
                }
                $seen[$key] = true;
            }
            $result[] = [
                'line' => $row['line'] ?? count($result) + 1,
                'tier' => $row['tier'] ?? '', 'sku' => $sku, 'moq' => $moq, 'price' => $price,
                'tier_id' => $tier?->id, 'variant_id' => $variant?->id,
                'action' => $tier && $variant ? ($current->has($tier->id.':'.$variant->id) ? 'Cập nhật' : 'Thêm mới') : '—',
                'errors' => $errors,
            ];
        }

        return $result;
    }

    /** @param list<array<string, mixed>> $source */
    public function apply(Product $product, array $source, int $actorId): int
    {
        $rows = $this->preview($product, $source);
        if (collect($rows)->contains(fn (array $row): bool => $row['errors'] !== [])) {
            throw ValidationException::withMessages(['import' => 'Dữ liệu đã thay đổi hoặc vẫn có dòng lỗi. Vui lòng xem trước lại.']);
        }
        DB::transaction(function () use ($rows, $actorId): void {
            foreach ($rows as $row) {
                $this->pricing->updateDealer(
                    ProductVariant::query()->findOrFail($row['variant_id']),
                    DealerTier::query()->findOrFail($row['tier_id']),
                    $row['price'], (int) $row['moq'], $actorId,
                );
            }
        }, 3);

        return count($rows);
    }
}
