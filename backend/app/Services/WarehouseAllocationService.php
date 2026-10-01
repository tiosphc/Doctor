<?php

namespace App\Services;

use App\Models\AdministrativeProvince;
use App\Models\AdministrativeWard;
use App\Models\InventoryBalance;
use App\Models\Warehouse;
use App\Models\WarehouseServiceArea;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class WarehouseAllocationService
{
    /** @param array<string, mixed> $recipient @return array<string, mixed> */
    public function normalizeRecipient(array $recipient): array
    {
        $provinceInput = (string) (($recipient['shipping_province_code'] ?? '') ?: ($recipient['shipping_province'] ?? ''));
        if (in_array($this->normalizedName($provinceInput), ['hcm', 'tp.hcm', 'ho chi minh city'], true)) {
            $provinceInput = '79';
        }
        $province = AdministrativeProvince::query()->find($provinceInput);
        if ($province === null) {
            $province = AdministrativeProvince::query()->get()->first(fn (AdministrativeProvince $entry): bool => $this->normalizedName($entry->name) === $this->normalizedName($provinceInput));
        }
        if ($province === null) {
            $this->fail('PROVINCE_NOT_FOUND');
        }
        $wardInput = (string) (($recipient['shipping_ward_code'] ?? '') ?: ($recipient['shipping_ward'] ?? ''));
        $ward = AdministrativeWard::query()->find($wardInput);
        if ($ward === null && $wardInput !== '') {
            $ward = AdministrativeWard::query()->where('province_code', $province->code)->get()
                ->first(fn (AdministrativeWard $entry): bool => $this->normalizedName($entry->name) === $this->normalizedName($wardInput));
        }
        if ($ward === null || $ward->province_code !== $province->code) {
            $this->fail('WARD_PROVINCE_MISMATCH');
        }

        return [
            'recipient_name' => $recipient['recipient_name'] ?? null,
            'recipient_phone' => $recipient['recipient_phone'] ?? null,
            'recipient_email' => $recipient['recipient_email'] ?? null,
            'shipping_address_line1' => $recipient['shipping_address_line1'] ?? null,
            'shipping_address_line2' => $recipient['shipping_address_line2'] ?? null,
            'shipping_city' => $province->name,
            'shipping_province' => $province->name,
            'shipping_country' => 'VN',
            'shipping_postal_code' => $recipient['shipping_postal_code'] ?? null,
            'shipping_province_code' => $province->code,
            'shipping_ward_code' => $ward->code,
            'shipping_ward' => $ward->name,
            'shipping_district' => $recipient['shipping_district'] ?? null,
            'delivery_note' => $recipient['delivery_note'] ?? null,
        ];
    }

    /** @param list<array{product_variant_id: int, quantity: string}> $items @return array{preferred: Warehouse, actual: Warehouse, fallback_used: bool, default_area_used: bool, sufficient: bool} */
    public function allocate(string $provinceCode, array $items): array
    {
        $areas = WarehouseServiceArea::query()->with('warehouse')
            ->where('province_code', $provinceCode)->orderBy('priority')->orderBy('warehouse_id')->get();
        $preferred = $areas->first(fn (WarehouseServiceArea $area): bool => $area->warehouse?->status === 'active')?->warehouse;
        $defaultAreaUsed = $preferred === null;
        if ($defaultAreaUsed) {
            $preferred = Warehouse::query()->where('status', 'active')->where('is_default_sales', true)->first();
        }
        if ($preferred === null) {
            $this->fail('WAREHOUSE_SERVICE_AREA_NOT_FOUND');
        }
        $warehouses = Warehouse::query()->where('status', 'active')
            ->whereIn('id', WarehouseServiceArea::query()->select('warehouse_id'))->orderBy('id')->get();
        $ordered = collect([$preferred])->concat($warehouses->where('id', '!=', $preferred->id))->unique('id');
        $variantIds = array_column($items, 'product_variant_id');
        $balances = InventoryBalance::query()->whereIn('warehouse_id', $ordered->pluck('id'))
            ->whereIn('product_variant_id', $variantIds)->get()
            ->keyBy(fn (InventoryBalance $balance): string => $balance->warehouse_id.'|'.$balance->product_variant_id);
        $demand = [];
        foreach ($items as $item) {
            $variantId = $item['product_variant_id'];
            $demand[$variantId] = bcadd($demand[$variantId] ?? '0.000', (string) $item['quantity'], 3);
        }
        foreach ($ordered as $warehouse) {
            $enough = true;
            foreach ($demand as $variantId => $quantity) {
                if (bccomp($balances->get($warehouse->id.'|'.$variantId)?->available_quantity ?? '0.000', $quantity, 3) < 0) {
                    $enough = false;
                    break;
                }
            }
            if ($enough) {
                return ['preferred' => $preferred, 'actual' => $warehouse,
                    'fallback_used' => $warehouse->id !== $preferred->id,
                    'default_area_used' => $defaultAreaUsed, 'sufficient' => true];
            }
        }

        return ['preferred' => $preferred, 'actual' => $preferred,
            'fallback_used' => false, 'default_area_used' => $defaultAreaUsed, 'sufficient' => false];
    }

    private function normalizedName(string $name): string
    {
        return preg_replace('/^(thanh pho|tinh|phuong|xa|dac khu)\s+/u', '', Str::lower(Str::ascii(trim($name)))) ?? '';
    }

    private function fail(string $code): never
    {
        $message = $code === 'WAREHOUSE_SERVICE_AREA_NOT_FOUND'
            ? 'Chưa có kho bán hàng đang hoạt động cho khu vực này. Vui lòng liên hệ quản trị viên hoặc chọn địa chỉ khác.'
            : $code;

        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message], 409));
    }
}
