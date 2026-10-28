<div class="min-w-0">
    <label for="{{ $kind }}-search" class="block text-sm font-semibold">{{ $label }}</label>
    <input id="{{ $kind }}-search" type="search" x-model="queries.{{ $kind }}" @focus="search('{{ $kind }}')" @input.debounce.300ms="search('{{ $kind }}')" maxlength="80" placeholder="Tìm theo mã hoặc tên" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal">
    <div x-cloak x-show="results.{{ $kind }}.length" class="mt-1 max-h-48 overflow-y-auto rounded-xl border border-slate-200 bg-white shadow-sm">
        <template x-for="item in results.{{ $kind }}" :key="item.id">
            <button type="button" @click="choose('{{ $kind }}', item)" x-text="item.label" class="block w-full border-b border-slate-100 px-3 py-2 text-left text-sm hover:bg-slate-50"></button>
        </template>
    </div>
    <div x-show="{{ $kind }}" class="mt-2 inline-flex max-w-full items-center gap-2 rounded-lg bg-slate-100 px-2 py-1 text-xs"><span class="truncate" x-text="{{ $kind }}?.label"></span><button type="button" @click="remove('{{ $kind }}')" aria-label="Bỏ lựa chọn" class="font-bold text-red-700">×</button></div>
    <input type="hidden" name="{{ $field }}" x-bind:value="{{ $kind }}?.id ?? ''">
    @error($errorKey)<p class="mt-1 text-sm text-red-700">{{ $message }}</p>@enderror
</div>
