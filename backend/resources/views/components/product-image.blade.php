@props(['image' => null, 'alt' => ''])

@if ($image)
    <img src="{{ $image->url }}" alt="{{ $image->alt_text ?: $alt }}" {{ $attributes->class(['bg-slate-100 object-cover']) }}>
@else
    <div {{ $attributes->class(['flex items-center justify-center bg-slate-100 text-center text-sm text-slate-500']) }}>Chưa có ảnh</div>
@endif
