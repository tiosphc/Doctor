<?php

namespace App\Http\Requests\Admin;

use App\Models\Product;
use App\Support\Sku;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class CompleteProductWizardRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $data = $this->input('data', []);
        $retail = is_array($data) && ($data['sellable_retail'] ?? false) === true && ! ($data['gift_only'] ?? false);
        $dealer = is_array($data) && ($data['sellable_dealer'] ?? false) === true && ! ($data['gift_only'] ?? false);
        $variants = is_array($data) && ($data['has_variants'] ?? false) === true;

        return [
            'data' => ['required', 'array'],
            'data.name' => ['required', 'string', 'max:255', 'not_regex:/^\s*$/u'],
            'data.sku' => ['required', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'data.product_category_id' => ['required', 'integer', Rule::exists('product_categories', 'id')->where('status', 'active')],
            'data.brand_id' => ['nullable', 'integer', Rule::exists('brands', 'id')->where('status', 'active')],
            'data.unit_id' => ['required', 'integer', Rule::exists('units', 'id')->where('status', 'active')],
            'data.sellable_retail' => ['required', 'boolean'],
            'data.sellable_dealer' => ['required', 'boolean'],
            'data.can_be_gift' => ['sometimes', 'boolean'],
            'data.gift_only' => ['sometimes', 'boolean'],
            'data.description' => ['nullable', 'string', 'max:20000'],
            'data.youtube_videos' => ['sometimes', 'array', 'max:10'],
            'data.youtube_videos.*' => ['required', 'url', 'max:500'],
            'data.has_variants' => ['required', 'boolean'],
            'data.attributes' => [$variants ? 'required' : 'sometimes', 'array', 'max:3'],
            'data.attributes.*.name' => [$variants ? 'required' : 'nullable', 'string', 'max:80'],
            'data.attributes.*.values' => [$variants ? 'required' : 'sometimes', 'array', 'max:20'],
            'data.attributes.*.values.*' => ['required', 'string', 'max:80'],
            'data.variants' => [$variants ? 'required' : 'sometimes', 'array', 'max:100'],
            'data.variants.*.sku' => [$variants ? 'required' : 'nullable', 'string', 'max:100', 'regex:/^[A-Z0-9][A-Z0-9._-]*$/'],
            'data.variants.*.specifications' => [$variants ? 'required' : 'sometimes', 'array'],
            'data.variants.*.image_id' => ['nullable', 'integer'],
            'data.variants.*.retail_price_override' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'data.variants.*.initial_stock' => ['nullable', 'integer', 'min:0'],
            'data.retail_price' => [$retail ? 'required' : 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'data.retail_breaks' => ['sometimes', 'array', 'size:0'],
            'data.dealer_rules' => [$dealer ? 'required' : 'sometimes', 'array', 'max:60'],
            'data.dealer_rules.*.tier_id' => ['required', 'integer', Rule::exists('dealer_tiers', 'id')->where('status', 'active')],
            'data.dealer_rules.*.sku' => ['required', 'string', 'max:100'],
            'data.dealer_rules.*.min_quantity' => ['required', 'integer', 'min:1'],
            'data.dealer_rules.*.unit_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'data.track_inventory' => ['required', 'boolean'],
            'data.warehouse_id' => ['nullable', 'integer', Rule::exists('warehouses', 'id')->where('status', 'active')],
            'data.initial_stock' => ['nullable', 'integer', 'min:0'],
            'data.low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'data.weight' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'data.length' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'data.width' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'data.height' => ['nullable', 'numeric', 'min:0', 'decimal:0,3'],
            'data.usage_instructions' => ['nullable', 'string', 'max:20000'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $data = $this->input('data');
        if (! is_array($data)) {
            return;
        }
        if (isset($data['name']) && is_string($data['name'])) {
            $data['name'] = trim($data['name']);
        }
        if (isset($data['sku']) && is_string($data['sku'])) {
            $data['sku'] = Sku::normalize($data['sku']);
        }
        foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $index => $variant) {
            if (is_array($variant) && isset($variant['sku']) && is_string($variant['sku'])) {
                $data['variants'][$index]['sku'] = Sku::normalize($variant['sku']);
            }
        }
        $this->merge(['data' => $data]);
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->input('data');
            if (! is_array($data)) {
                return;
            }
            $product = $this->route('product');
            $productId = $product instanceof Product ? $product->id : null;
            if ($product instanceof Product && $product->status === 'active' && $product->wizard_key !== null) {
                return;
            }
            $sku = $data['sku'] ?? null;
            if (is_string($sku) && $sku !== '' &&
                (DB::table('products')->where('base_sku', $sku)->when($productId, fn ($query) => $query->where('id', '!=', $productId))->exists()
                    || DB::table('product_variants')->where('sku', $sku)->exists())) {
                $validator->errors()->add('data.sku', 'SKU này đã tồn tại.');
            }
            if (($data['gift_only'] ?? false) && ! ($data['can_be_gift'] ?? false)) {
                $validator->errors()->add('data.can_be_gift', 'Gift-only Product must be enabled for gifts.');
            }
            if (($data['gift_only'] ?? false) && ! ($data['track_inventory'] ?? false)) {
                $validator->errors()->add('data.track_inventory', 'Gift-only Product must track inventory.');
            }
            if (! ($data['sellable_retail'] ?? false) && ! ($data['sellable_dealer'] ?? false) && ! ($data['gift_only'] ?? false)) {
                $validator->errors()->add('data.channels', 'Vui lòng chọn ít nhất một kênh bán.');
            }
            foreach (is_array($data['youtube_videos'] ?? null) ? $data['youtube_videos'] : [] as $index => $url) {
                if (! is_string($url) || ! $this->isYoutubeUrl($url)) {
                    $validator->errors()->add("data.youtube_videos.$index", 'Đường dẫn YouTube không hợp lệ.');
                }
            }
            $this->validateAttributesAndVariants($validator, $data);
            $this->validatePriceThresholds($validator, $data);
            $hasStock = (float) ($data['initial_stock'] ?? 0) > 0;
            foreach (is_array($data['variants'] ?? null) ? $data['variants'] : [] as $variant) {
                if (is_array($variant)) {
                    $hasStock = $hasStock || (float) ($variant['initial_stock'] ?? 0) > 0;
                }
            }
            if ($hasStock && ! ($data['track_inventory'] ?? false)) {
                $validator->errors()->add('data.track_inventory', 'Bật theo dõi tồn kho trước khi nhập tồn đầu kỳ.');
            }
            if ($hasStock && empty($data['warehouse_id'])) {
                $validator->errors()->add('data.warehouse_id', 'Vui lòng chọn kho cho tồn đầu kỳ.');
            }
            if (($data['has_variants'] ?? false) && (float) ($data['initial_stock'] ?? 0) > 0) {
                $validator->errors()->add('data.initial_stock', 'Tồn đầu kỳ phải nhập theo từng biến thể.');
            }
        });
    }

    /** @param array<string, mixed> $data */
    private function validateAttributesAndVariants(Validator $validator, array $data): void
    {
        if (! ($data['has_variants'] ?? false)) {
            return;
        }
        $attributes = $data['attributes'] ?? [];
        if (! is_array($attributes) || $attributes === []) {
            $validator->errors()->add('data.attributes', 'Thêm ít nhất một thuộc tính biến thể.');

            return;
        }
        $names = [];
        $expected = [[]];
        foreach ($attributes as $index => $attribute) {
            if (! is_array($attribute)) {
                continue;
            }
            $name = is_string($attribute['name'] ?? null) ? trim($attribute['name']) : '';
            $key = mb_strtolower($name);
            if ($name === '' || isset($names[$key])) {
                $validator->errors()->add("data.attributes.$index.name", $name === '' ? 'Tên thuộc tính là bắt buộc.' : 'Tên thuộc tính biến thể bị trùng.');
            }
            $names[$key] = true;
            $values = $attribute['values'] ?? [];
            if (! is_array($values) || $values === []) {
                $validator->errors()->add("data.attributes.$index.values", 'Thêm ít nhất một giá trị.');

                continue;
            }
            $seen = [];
            $next = [];
            foreach ($values as $valueIndex => $value) {
                $value = is_string($value) ? trim($value) : '';
                $valueKey = mb_strtolower($value);
                if ($value === '' || isset($seen[$valueKey])) {
                    $validator->errors()->add("data.attributes.$index.values.$valueIndex", $value === '' ? 'Giá trị là bắt buộc.' : 'Giá trị biến thể bị trùng.');
                }
                $seen[$valueKey] = true;
                foreach ($expected as $combination) {
                    $next[] = [...$combination, $name => $value];
                }
            }
            $expected = $next;
        }
        if (count($expected) > 100) {
            $validator->errors()->add('data.variants', 'Tối đa 100 biến thể.');

            return;
        }
        $variants = $data['variants'] ?? [];
        if (! is_array($variants) || count($variants) !== count($expected)) {
            $validator->errors()->add('data.variants', 'Hãy tạo đầy đủ các tổ hợp biến thể.');

            return;
        }
        $expectedKeys = array_fill_keys(array_map(fn (array $combination): string => json_encode($combination), $expected), true);
        $seenSkus = [];
        $seenCombinations = [];
        foreach ($variants as $index => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            $specifications = $variant['specifications'] ?? [];
            $combination = is_array($specifications) ? json_encode($specifications) : '';
            if (! isset($expectedKeys[$combination]) || isset($seenCombinations[$combination])) {
                $validator->errors()->add("data.variants.$index.specifications", 'Tổ hợp biến thể không hợp lệ hoặc bị trùng.');
            }
            $seenCombinations[$combination] = true;
            $sku = $variant['sku'] ?? '';
            if (! is_string($sku) || $sku === '') {
                continue;
            }
            if (isset($seenSkus[$sku]) || $sku === ($data['sku'] ?? null) || DB::table('product_variants')->where('sku', $sku)->exists()) {
                $validator->errors()->add("data.variants.$index.sku", 'SKU này đã tồn tại.');
            }
            $seenSkus[$sku] = true;
        }
    }

    /** @param array<string, mixed> $data */
    private function validatePriceThresholds(Validator $validator, array $data): void
    {
        $skus = ($data['has_variants'] ?? false)
            ? array_column(is_array($data['variants'] ?? null) ? $data['variants'] : [], 'sku')
            : [$data['sku'] ?? null];
        $seen = [];
        foreach (is_array($data['dealer_rules'] ?? null) ? $data['dealer_rules'] : [] as $index => $row) {
            if (! is_array($row)) {
                continue;
            }
            $tier = is_scalar($row['tier_id'] ?? null) ? (string) $row['tier_id'] : '';
            $sku = is_string($row['sku'] ?? null) ? Sku::normalize($row['sku']) : '';
            if (! in_array($sku, $skus, true)) {
                $validator->errors()->add("data.dealer_rules.$index.sku", 'Chọn biến thể hợp lệ của sản phẩm.');
            }
            $key = $tier.':'.$sku;
            if ($tier !== '' && $sku !== '' && isset($seen[$key])) {
                $validator->errors()->add("data.dealer_rules.$index.sku", 'Giá của Tier '.$tier.' cho biến thể '.$sku.' đã tồn tại.');
            }
            $seen[$key] = true;
        }
        if (($data['sellable_dealer'] ?? false) && empty($data['dealer_rules'])) {
            $validator->errors()->add('data.dealer_rules', 'Thêm ít nhất một mức giá đại lý.');
        }
    }

    private function isYoutubeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (($parts['scheme'] ?? null) !== 'https') {
            return false;
        }
        $host = mb_strtolower($parts['host'] ?? '');
        if ($host === 'youtu.be') {
            return trim($parts['path'] ?? '', '/') !== '';
        }
        if (! in_array($host, ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'www.youtube-nocookie.com'], true)) {
            return false;
        }
        if (in_array($parts['path'] ?? '', ['/watch'], true)) {
            parse_str($parts['query'] ?? '', $query);

            return ! empty($query['v']);
        }

        return preg_match('#^/(shorts|embed)/[A-Za-z0-9_-]+#', $parts['path'] ?? '') === 1;
    }
}
