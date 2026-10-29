<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Models\Service;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesServiceEditorialContent
{
    protected function prepareForValidation(): void
    {
        $content = $this->input('content');

        if (! is_string($content)) {
            return;
        }

        $decoded = json_decode($content, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            $this->merge(['content' => $decoded]);
        }
    }

    /** @return array<string, mixed> */
    protected function serviceEditorialRules(): array
    {
        return [
            'content' => ['sometimes', 'nullable', 'array:benefits,process,results,faq'],
            'content.benefits' => ['required_with:content', 'array:title,description,items'],
            'content.benefits.title' => ['nullable', 'string', 'max:255'],
            'content.benefits.description' => ['nullable', 'string', 'max:10000'],
            'content.benefits.items' => ['array', 'max:100'],
            'content.benefits.items.*' => ['array:title,description'],
            'content.benefits.items.*.title' => ['nullable', 'string', 'max:255'],
            'content.benefits.items.*.description' => ['nullable', 'string', 'max:10000'],

            'content.process' => ['required_with:content', 'array:title,description,steps'],
            'content.process.title' => ['nullable', 'string', 'max:255'],
            'content.process.description' => ['nullable', 'string', 'max:10000'],
            'content.process.steps' => ['array', 'max:100'],
            'content.process.steps.*' => ['array:title,description'],
            'content.process.steps.*.title' => ['nullable', 'string', 'max:255'],
            'content.process.steps.*.description' => ['nullable', 'string', 'max:10000'],

            'content.results' => ['required_with:content', 'array:title,description,cases,disclaimer'],
            'content.results.title' => ['nullable', 'string', 'max:255'],
            'content.results.description' => ['nullable', 'string', 'max:10000'],
            'content.results.cases' => ['array', 'max:100'],
            'content.results.cases.*' => ['array:before_image,after_image,caption'],
            'content.results.cases.*.before_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'content.results.cases.*.after_image' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'content.results.cases.*.caption' => ['nullable', 'string', 'max:10000'],
            'content.results.disclaimer' => ['nullable', 'string', 'max:10000'],

            'content.faq' => ['required_with:content', 'array:title,items'],
            'content.faq.title' => ['nullable', 'string', 'max:255'],
            'content.faq.items' => ['array', 'max:100'],
            'content.faq.items.*' => ['array:question,answer'],
            'content.faq.items.*.question' => ['nullable', 'string', 'max:10000'],
            'content.faq.items.*.answer' => ['nullable', 'string', 'max:10000'],

            'result_images' => ['sometimes', 'array'],
            'result_images.*' => ['array:before_image,after_image'],
            'result_images.*.before_image' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'result_images.*.after_image' => ['sometimes', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $content = $this->contentForValidation();

            if (is_array($content)) {
                $this->rejectUnsafeText($validator, $content, 'content');
            }

            foreach ([
                'description',
                'short_description',
                'introduction',
                'duration_note',
                'price_note',
                'hero_disclaimer',
                'cta_label',
                'seo_title',
                'seo_description',
            ] as $field) {
                $value = $this->input($field);

                if (is_string($value) && preg_match('/<[^>]*>/u', $value) === 1) {
                    $validator->errors()->add($field, 'Nội dung chỉ được nhập văn bản thuần, không hỗ trợ HTML.');
                }
            }

            $resultCases = is_array($content['results']['cases'] ?? null)
                ? $content['results']['cases']
                : [];

            foreach ($this->file('result_images', []) as $index => $files) {
                if (! is_array($files) || ! array_key_exists((int) $index, $resultCases)) {
                    $validator->errors()->add(
                        "result_images.{$index}",
                        'Ảnh kết quả phải gắn với một trường hợp trước và sau hợp lệ.',
                    );
                }
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function contentForValidation(): ?array
    {
        $content = $this->input('content');

        if (is_array($content)) {
            return $content;
        }

        $service = $this->route('service');

        return $service instanceof Service && is_array($service->content)
            ? $service->content
            : null;
    }

    /** @param array<string, mixed> $value */
    private function rejectUnsafeText(Validator $validator, array $value, string $path): void
    {
        foreach ($value as $key => $item) {
            $itemPath = $path.'.'.$key;

            if (is_array($item)) {
                $this->rejectUnsafeText($validator, $item, $itemPath);
            } elseif (is_string($item) && preg_match('/<[^>]*>/u', $item) === 1) {
                $validator->errors()->add($itemPath, 'Nội dung chỉ được nhập văn bản thuần, không hỗ trợ HTML.');
            }
        }
    }
}
