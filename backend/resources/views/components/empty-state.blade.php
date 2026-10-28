@props(['title', 'description' => null])

<div {{ $attributes->class(['rounded-2xl border border-dashed border-slate-300 bg-white px-6 py-12 text-center']) }}>
    <h2 class="text-lg font-semibold text-junie-navy">{{ $title }}</h2>
    @if ($description)
        <p class="mx-auto mt-2 max-w-xl text-sm leading-6 text-slate-600">{{ $description }}</p>
    @endif
    @if ($slot->isNotEmpty())
        <div class="mt-5">{{ $slot }}</div>
    @endif
</div>
