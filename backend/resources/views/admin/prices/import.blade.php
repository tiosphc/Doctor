@extends('layouts.admin')

@section('title', 'Import giá đại lý')
@section('page-title', 'Import giá đại lý')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <a href="{{ route('admin.web.prices.dealer.index', ['product_id' => $product->id]) }}" class="text-sm font-semibold text-junie-navy hover:underline">← Bảng giá đại lý</a>
    <header><h1 class="text-3xl font-semibold text-junie-navy">Import giá đại lý</h1><p class="mt-2 text-sm text-slate-600">{{ $product->name }} · {{ $product->product_code }}. Kiểm tra toàn bộ dòng trước khi áp dụng; nếu có lỗi sẽ không ghi giá nào.</p></header>
    @error('import')<x-alert tone="error">{{ $message }}</x-alert>@enderror

    <div class="grid gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('admin.web.prices.dealer.import.preview', $product) }}" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <input type="hidden" name="source" value="paste">
            <div><h2 class="text-lg font-semibold text-junie-navy">Sao chép và dán</h2><p class="mt-1 text-sm text-slate-600">Mỗi dòng: <code>Tier SKU MOQ Giá</code>. Tier dùng mã hoặc tên đang hoạt động; SKU phải thuộc sản phẩm này.</p></div>
            <textarea name="paste" rows="8" maxlength="30000" class="w-full rounded-lg border border-slate-300 p-3 font-mono text-sm" placeholder="GOLD SKU-001 1 204250">{{ old('paste') }}</textarea>
            @error('paste')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <x-button type="submit">Xem trước dữ liệu dán</x-button>
        </form>
        <form method="POST" action="{{ route('admin.web.prices.dealer.import.preview', $product) }}" enctype="multipart/form-data" class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            @csrf
            <input type="hidden" name="source" value="file">
            <div><h2 class="text-lg font-semibold text-junie-navy">Upload CSV / XLS / XLSX</h2><p class="mt-1 text-sm text-slate-600">Trang tính đầu tiên, bốn cột đúng thứ tự: Tier, SKU, MOQ, Price. Tối đa 5 MB và 60 dòng.</p></div>
            <input type="file" name="file" accept=".csv,.xls,.xlsx" required class="block w-full rounded-lg border border-slate-300 p-3 text-sm">
            @error('file')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <div class="flex flex-wrap items-center gap-4"><x-button type="submit">Xem trước file</x-button><a href="{{ route('admin.web.prices.dealer.import.template', $product) }}" class="text-sm font-semibold text-junie-navy hover:underline">Tải mẫu CSV</a></div>
        </form>
    </div>

    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-junie-navy">Tier và SKU hiện có</h2>
        <div class="mt-3 grid gap-4 text-sm sm:grid-cols-2"><div><p class="font-semibold">Tier đang hoạt động</p><p class="mt-1 text-slate-600">{{ $tiers->pluck('code')->join(', ') ?: 'Chưa có' }}</p></div><div><p class="font-semibold">SKU của sản phẩm</p><p class="mt-1 break-all font-mono text-xs text-slate-600">{{ $variants->pluck('sku')->join(', ') ?: 'Chưa có' }}</p></div></div>
    </section>

    @if($preview !== null)
        <section class="space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="text-lg font-semibold text-junie-navy">Xem trước</h2><p class="mt-1 text-sm text-slate-600">{{ collect($preview)->where('errors', [])->count() }} hợp lệ · {{ collect($preview)->filter(fn ($row) => $row['errors'] !== [])->count() }} lỗi</p></div>
                @if(collect($preview)->every(fn ($row) => $row['errors'] === []))
                    <form method="POST" action="{{ route('admin.web.prices.dealer.import.apply', $product) }}">@csrf<x-button type="submit">Áp dụng {{ count($preview) }} dòng giá</x-button></form>
                @endif
            </div>
            <div class="max-h-96 overflow-auto rounded-lg border border-slate-200"><table class="w-full min-w-[680px] text-left text-sm"><thead class="sticky top-0 bg-slate-50"><tr><th class="px-3 py-2">Dòng</th><th class="px-3 py-2">Tier</th><th class="px-3 py-2">SKU</th><th class="px-3 py-2">MOQ</th><th class="px-3 py-2">Giá</th><th class="px-3 py-2">Hành động / Lỗi</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach($preview as $row)<tr class="{{ $row['errors'] !== [] ? 'bg-red-50' : '' }}"><td class="px-3 py-2">{{ $row['line'] }}</td><td class="px-3 py-2">{{ $row['tier'] }}</td><td class="break-all px-3 py-2 font-mono text-xs">{{ $row['sku'] }}</td><td class="px-3 py-2">{{ $row['moq'] }}</td><td class="px-3 py-2">{{ $row['price'] }}</td><td class="px-3 py-2">@if($row['errors'] === [])<span class="font-semibold text-emerald-700">{{ $row['action'] }}</span>@else<span class="text-red-700">{{ implode(' ', $row['errors']) }}</span>@endif</td></tr>@endforeach</tbody></table></div>
        </section>
    @endif
</div>
@endsection
