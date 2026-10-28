<?php

namespace App\Http\Requests\Admin;

class SaveBladeProductVariantRequest extends SaveProductVariantRequest
{
    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'variant_name.required' => 'Tên biến thể là bắt buộc.',
            'sku.required' => 'Mã SKU là bắt buộc.',
            'sku.unique' => 'Mã SKU đã tồn tại.',
            'unit_id.required' => 'Vui lòng chọn đơn vị.',
            'unit_id.exists' => 'Đơn vị đã chọn không còn hoạt động.',
        ];
    }
}
