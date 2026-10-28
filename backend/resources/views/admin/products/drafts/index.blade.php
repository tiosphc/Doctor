@extends('layouts.admin')

@section('title', 'Sản phẩm nháp')
@section('page-title', 'Sản phẩm nháp')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Product Master</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Sản phẩm nháp</h1><p class="mt-2 text-sm text-slate-600">Bản nháp do tài khoản của bạn tạo, có thể tiếp tục sau khi tải lại trang.</p></div>
        <a href="{{ route('admin.web.products.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">Danh sách sản phẩm →</a>
    </header>
    <form method="GET" action="{{ route('admin.web.products.drafts.index') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <label class="min-w-52 flex-1 text-sm font-semibold text-slate-700">Tên hoặc mã sản phẩm<input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3" placeholder="Tìm bản nháp"></label>
        <x-button type="submit">Tìm</x-button><a href="{{ route('admin.web.products.drafts.index') }}" class="pb-3 text-sm font-semibold text-junie-navy hover:underline">Xóa lọc</a>
    </form>
    <p class="text-sm text-slate-500">{{ $drafts->total() }} bản nháp · Trang {{ $drafts->currentPage() }}/{{ $drafts->lastPage() }}</p>
    <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
        <table class="w-full min-w-[680px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Sản phẩm</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Ảnh / SKU</th><th class="px-4 py-3">Tạo lúc</th><th class="px-4 py-3">Cập nhật</th><th class="px-4 py-3">Thao tác</th></tr></thead><tbody class="divide-y divide-slate-100">
        @forelse($drafts as $draft)
            <tr><td class="px-4 py-3"><span class="block font-semibold text-junie-navy">{{ $draft->name ?: 'Chưa đặt tên' }}</span><span class="font-mono text-xs text-slate-500">{{ $draft->product_code }}</span></td><td class="px-4 py-3"><x-product-status status="draft" />@if(!$draft->wizard_key)<span class="mt-1 block text-xs text-red-700">Thiếu khóa wizard</span>@endif</td><td class="px-4 py-3">{{ $draft->images_count }} ảnh · {{ $draft->variants_count }} SKU</td><td class="px-4 py-3 whitespace-nowrap">{{ $draft->created_at?->format('d/m/Y H:i') }}</td><td class="px-4 py-3 whitespace-nowrap">{{ $draft->updated_at?->format('d/m/Y H:i') }}</td><td class="px-4 py-3"><a href="{{ route('admin.web.products.drafts.edit', $draft) }}" class="font-semibold text-junie-navy hover:underline">{{ $draft->wizard_key ? 'Tiếp tục →' : 'Xem vấn đề →' }}</a></td></tr>
        @empty
            <tr><td colspan="6" class="px-4 py-10 text-center text-slate-500">Không có bản nháp phù hợp.</td></tr>
        @endforelse
        </tbody></table>
    </div>
    <x-pagination :paginator="$drafts" />
</div>
@endsection
