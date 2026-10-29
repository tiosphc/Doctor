<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesServiceEditorialContent;
use App\Models\Service;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreServiceRequest extends FormRequest
{
    use ValidatesServiceEditorialContent;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer', 'exists:service_categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::unique('services', 'slug')],
            'description' => ['nullable', 'string', 'max:5000'],
            'short_description' => ['nullable', 'string', 'max:10000'],
            'introduction' => ['nullable', 'string', 'max:50000'],
            'duration' => ['required', 'integer', 'min:1', 'max:1440'],
            'duration_note' => ['nullable', 'string', 'max:255'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999999999.99', 'decimal:0,2'],
            'price_note' => ['nullable', 'string', 'max:255'],
            'image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'hero_image' => ['nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'hero_disclaimer' => ['nullable', 'string', 'max:10000'],
            'cta_label' => ['nullable', 'string', 'max:255'],
            'status' => ['sometimes', Rule::in(Service::STATUSES)],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:4294967295'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:10000'],
            ...$this->serviceEditorialRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.required' => 'Vui lòng nhập đường dẫn dịch vụ.',
            'slug.regex' => 'Đường dẫn chỉ được gồm chữ thường, số và dấu gạch ngang.',
            'slug.unique' => 'Đường dẫn dịch vụ đã tồn tại.',
            'content.array' => 'Nội dung chi tiết phải là JSON hợp lệ.',
            'content.benefits.required_with' => 'Nội dung chi tiết phải có phần tác dụng và ưu điểm.',
            'content.process.required_with' => 'Nội dung chi tiết phải có phần quy trình.',
            'content.results.required_with' => 'Nội dung chi tiết phải có phần kết quả trước và sau.',
            'content.faq.required_with' => 'Nội dung chi tiết phải có phần câu hỏi thường gặp.',
            'result_images.*.before_image.image' => 'Ảnh trước phải là tệp hình ảnh hợp lệ.',
            'result_images.*.after_image.image' => 'Ảnh sau phải là tệp hình ảnh hợp lệ.',
            'result_images.*.before_image.max' => 'Ảnh trước không được vượt quá 5 MB.',
            'result_images.*.after_image.max' => 'Ảnh sau không được vượt quá 5 MB.',
        ];
    }
}
