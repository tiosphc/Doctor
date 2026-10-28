@props(['action', 'method' => 'POST', 'title', 'message', 'confirmLabel' => 'Xác nhận', 'tone' => 'danger', 'fields' => []])

<div x-data="{ open: false }" @keydown.escape.window="open = false">
    <button type="button" @click="open = true" {{ $attributes }}>{{ $trigger ?? 'Mở xác nhận' }}</button>
    <div x-cloak x-show="open" class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/60 p-4" role="presentation" @click.self="open = false">
        <div role="dialog" aria-modal="true" aria-label="{{ $title }}" class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl">
            <h2 class="text-lg font-semibold text-junie-navy">{{ $title }}</h2>
            <p class="mt-2 text-sm leading-6 text-slate-600">{{ $message }}</p>
            <div class="mt-6 flex justify-end gap-3">
                <x-button variant="secondary" @click="open = false">Đóng</x-button>
                <form method="POST" action="{{ $action }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                    @csrf
                    @if (strtoupper($method) !== 'POST')
                        @method($method)
                    @endif
                    @foreach($fields as $name => $value)
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endforeach
                    <x-button type="submit" :variant="$tone" x-bind:disabled="submitting">{{ $confirmLabel }}</x-button>
                </form>
            </div>
        </div>
    </div>
</div>
