<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AdminServiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->when($this->relationLoaded('category'), $this->category_id),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'short_description' => $this->short_description,
            'introduction' => $this->introduction,
            'duration' => $this->duration,
            'duration_note' => $this->duration_note,
            'price' => $this->price,
            'price_note' => $this->price_note,
            'image' => $this->resolveImageUrl($this->image),
            'hero_image' => $this->resolveImageUrl($this->hero_image),
            'hero_disclaimer' => $this->hero_disclaimer,
            'cta_label' => $this->cta_label,
            'sort_order' => $this->sort_order,
            'seo_title' => $this->seo_title,
            'seo_description' => $this->seo_description,
            'content' => is_array($this->content) ? $this->normalizeContent($this->content) : null,
            'content_image_urls' => $this->contentImageUrls(),
            'result_image_urls' => $this->resultImageUrls(),
            'category' => $this->whenLoaded('category', fn (): ?string => $this->category?->name),
            'category_slug' => $this->whenLoaded('category', fn (): ?string => $this->category?->slug),
            'status' => $this->status,
        ];
    }

    /** @return array<string, mixed>|null */
    private function normalizeContent(?array $content): ?array
    {
        if ($content === null) {
            return null;
        }

        if (! is_array($content['blocks'] ?? null)) {
            return $this->normalizeFixedContent($content);
        }

        $fixed = [
            'benefits' => ['title' => null, 'description' => null, 'items' => []],
            'process' => ['title' => null, 'description' => null, 'steps' => []],
            'results' => ['title' => null, 'description' => null, 'cases' => [], 'disclaimer' => null],
            'faq' => ['title' => null, 'items' => []],
        ];
        $recognized = false;

        foreach ($content['blocks'] as $block) {
            if (! is_array($block)) {
                continue;
            }

            $type = $block['type'] ?? null;

            if ($type === 'overview') {
                $recognized = true;
                $fixed['benefits']['title'] = $block['heading'] ?? $block['eyebrow'] ?? null;
                $fixed['benefits']['description'] = $block['body'] ?? $block['description'] ?? null;
                $fixed['benefits']['items'] = array_map(
                    fn (mixed $item): array => is_array($item)
                        ? ['title' => $item['title'] ?? null, 'description' => $item['description'] ?? $item['text'] ?? null]
                        : ['title' => is_string($item) ? $item : null, 'description' => null],
                    is_array($block['expect_items'] ?? null) ? $block['expect_items'] : [],
                );
            } elseif ($type === 'process') {
                $recognized = true;
                $fixed['process']['title'] = $block['heading'] ?? $block['eyebrow'] ?? null;
                $fixed['process']['description'] = $block['body'] ?? $block['description'] ?? null;
                $fixed['process']['steps'] = array_map(
                    fn (mixed $step): array => is_array($step)
                        ? ['title' => $step['title'] ?? null, 'description' => $step['description'] ?? $step['text'] ?? null]
                        : ['title' => null, 'description' => null],
                    is_array($block['steps'] ?? null) ? $block['steps'] : [],
                );
            } elseif ($type === 'faq') {
                $recognized = true;
                $fixed['faq']['title'] = $block['heading'] ?? $block['eyebrow'] ?? null;
                $fixed['faq']['items'] = array_map(
                    fn (mixed $item): array => is_array($item)
                        ? ['question' => $item['question'] ?? $item['q'] ?? null, 'answer' => $item['answer'] ?? $item['a'] ?? null]
                        : ['question' => null, 'answer' => null],
                    is_array($block['items'] ?? null) ? $block['items'] : [],
                );
            }
        }

        return $recognized ? $fixed : $content;
    }

    /** @param array<string, mixed> $content @return array<string, mixed> */
    private function normalizeFixedContent(array $content): array
    {
        $normalized = [
            'benefits' => ['title' => null, 'description' => null, 'items' => []],
            'process' => ['title' => null, 'description' => null, 'steps' => []],
            'results' => ['title' => null, 'description' => null, 'cases' => [], 'disclaimer' => null],
            'faq' => ['title' => null, 'items' => []],
        ];

        if (is_array($content['benefits'] ?? null)) {
            $normalized['benefits']['title'] = $content['benefits']['title'] ?? null;
            $normalized['benefits']['description'] = $content['benefits']['description'] ?? null;
            $normalized['benefits']['items'] = $this->normalizePairs($content['benefits']['items'] ?? [], ['title', 'description']);
        }

        if (is_array($content['process'] ?? null)) {
            $normalized['process']['title'] = $content['process']['title'] ?? null;
            $normalized['process']['description'] = $content['process']['description'] ?? null;
            $normalized['process']['steps'] = $this->normalizePairs($content['process']['steps'] ?? [], ['title', 'description']);
        }

        if (is_array($content['results'] ?? null)) {
            $normalized['results']['title'] = $content['results']['title'] ?? null;
            $normalized['results']['description'] = $content['results']['description'] ?? null;
            $normalized['results']['disclaimer'] = $content['results']['disclaimer'] ?? null;
            $normalized['results']['cases'] = array_map(
                fn (mixed $case): array => is_array($case) ? [
                    'before_image' => $case['before_image'] ?? null,
                    'after_image' => $case['after_image'] ?? null,
                    'caption' => $case['caption'] ?? null,
                ] : ['before_image' => null, 'after_image' => null, 'caption' => null],
                is_array($content['results']['cases'] ?? null) ? $content['results']['cases'] : [],
            );
        }

        if (is_array($content['faq'] ?? null)) {
            $normalized['faq']['title'] = $content['faq']['title'] ?? null;
            $normalized['faq']['items'] = $this->normalizePairs($content['faq']['items'] ?? [], ['question', 'answer']);
        }

        return $normalized;
    }

    /** @param mixed $items @param list<string> $keys @return list<array<string, mixed>> */
    private function normalizePairs(mixed $items, array $keys): array
    {
        if (! is_array($items)) {
            return [];
        }

        return array_values(array_map(
            fn (mixed $item): array => is_array($item)
                ? array_combine($keys, array_map(fn (string $key): mixed => $item[$key] ?? null, $keys))
                : array_combine($keys, array_fill(0, count($keys), null)),
            $items,
        ));
    }

    /** @return array<string, string> */
    private function contentImageUrls(): array
    {
        if (! is_array($this->content) || ! is_array($this->content['blocks'] ?? null)) {
            return [];
        }

        $images = [];

        foreach ($this->content['blocks'] as $block) {
            if (! is_array($block) || ! isset($block['id']) || ! is_string($block['image'] ?? null)) {
                continue;
            }

            $url = $this->resolveImageUrl($block['image']);

            if ($url !== null) {
                $images[(string) $block['id']] = $url;
            }
        }

        return $images;
    }

    /** @return array<int, array<string, string>> */
    private function resultImageUrls(): array
    {
        $cases = is_array($this->content['results']['cases'] ?? null)
            ? $this->content['results']['cases']
            : [];
        $images = [];

        foreach ($cases as $index => $case) {
            if (! is_array($case)) {
                continue;
            }

            foreach (['before_image', 'after_image'] as $side) {
                $url = $this->resolveImageUrl($case[$side] ?? null);

                if ($url !== null) {
                    $images[(int) $index][$side] = $url;
                }
            }
        }

        return $images;
    }

    private function resolveImageUrl(mixed $image): ?string
    {
        if (! is_string($image) || $image === '') {
            return null;
        }

        if (filter_var($image, FILTER_VALIDATE_URL) !== false || str_starts_with($image, '//')) {
            return $image;
        }

        $url = Storage::disk('public')->url($image);

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : url($url);
    }
}
