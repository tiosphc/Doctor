@extends('layouts.admin')

@section('title', 'Tồn kho')
@section('page-title', 'Tồn kho')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Sản phẩm / Kho</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Tồn kho</h1><p class="mt-2 text-sm text-slate-600">Số dư theo từng SKU và kho. Sản phẩm chưa phát sinh tồn vẫn có thể thiết lập tồn đầu kỳ.</p></div>
        <div class="flex flex-wrap gap-3 text-sm font-semibold"><a href="{{ route('admin.web.warehouses.index') }}" class="text-junie-navy hover:underline">Quản lý kho →</a><a href="{{ route('admin.web.inventory.movements') }}" class="text-junie-navy hover:underline">Lịch sử biến động →</a><a href="{{ route('admin.web.inventory.reconciliation') }}" class="text-junie-navy hover:underline">Đối soát sổ kho →</a></div>
    </header>
    <form method="GET" action="{{ route('admin.web.inventory.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-4">
        @if(!empty($filters['product_id']))<input type="hidden" name="product_id" value="{{ $filters['product_id'] }}">@endif
        <label class="text-sm font-semibold text-slate-700">Tìm sản phẩm / SKU<input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3" placeholder="Tên, mã, SKU, biến thể"></label>
        <label class="text-sm font-semibold text-slate-700">Kho<select name="warehouse_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả kho</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)($filters['warehouse_id'] ?? '') === (string)$warehouse->id)>{{ $warehouse->code }} · {{ $warehouse->name }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold text-slate-700">Trạng thái SKU<select name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3"><option value="">Tất cả</option><option value="active" @selected(($filters['status'] ?? '') === 'active')>Đang hoạt động</option><option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Ngừng hoạt động</option></select></label>
        <label class="flex items-center gap-2 self-end pb-3 text-sm font-semibold text-slate-700"><input type="checkbox" name="low_stock" value="1" @checked($filters['low_stock'] ?? false) class="size-4 rounded border-slate-300">Chỉ tồn thấp</label>
        <div class="flex flex-wrap items-center gap-3 sm:col-span-2 xl:col-span-4"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.inventory.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $products->total() }} sản phẩm · Trang {{ $products->currentPage() }}/{{ $products->lastPage() }}</p>
    @forelse($products as $product)
        <details class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm" @if($loop->first || !empty($filters['search']) || !empty($filters['product_id'])) open @endif>
            <summary class="flex cursor-pointer items-center gap-3 p-4 hover:bg-slate-50">
                @if($product->images->first())<img src="{{ $product->images->first()->url }}" alt="" loading="lazy" class="size-11 rounded-lg object-cover">@endif
                <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-junie-navy">{{ $product->name }}</span><span class="block text-xs text-slate-500">{{ $product->product_code }} · {{ $product->variants->count() }} SKU</span></span>
                @if($low_stock_counts[$product->id] > 0)<x-badge tone="gold">{{ $low_stock_counts[$product->id] }} SKU tồn thấp</x-badge>@endif
            </summary>
            <div class="overflow-x-auto border-t border-slate-200"><table class="w-full min-w-[790px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">SKU / Biến thể</th><th class="px-4 py-3">Kho</th><th class="px-4 py-3">Tồn thực tế</th><th class="px-4 py-3">Đã giữ</th><th class="px-4 py-3">Khả dụng</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Chi tiết</th></tr></thead><tbody class="divide-y divide-slate-100">
                @foreach($product->variants as $variant)
                    @php($variantBalances = $balances->get($variant->id, collect()))
                    @forelse($variantBalances as $balance)
                        <tr><td class="px-4 py-3"><span class="block font-semibold">{{ $variant->variant_name ?: 'Mặc định' }}</span><span class="break-all font-mono text-xs text-slate-500">{{ $variant->sku }} · {{ $variant->unit?->symbol }}</span></td><td class="px-4 py-3">{{ $balance->warehouse->code }} · {{ $balance->warehouse->name }}</td><td class="px-4 py-3 tabular-nums"><x-formatted-number :value="$balance->on_hand_quantity" /></td><td class="px-4 py-3 tabular-nums"><x-formatted-number :value="$balance->reserved_quantity" /></td><td class="px-4 py-3 tabular-nums font-semibold"><x-formatted-number :value="$balance->available_quantity" /></td><td class="px-4 py-3">@if($variant->status !== 'active' || !$variant->track_inventory)<x-badge tone="navy">Ngừng theo dõi</x-badge>@elseif(isset($low_balance_ids[$balance->id]))<x-badge tone="gold">Tồn thấp</x-badge>@else<x-badge tone="success">Bình thường</x-badge>@endif</td><td class="px-4 py-3"><a href="{{ route('admin.web.inventory.show', $variant) }}" class="font-semibold text-junie-navy hover:underline">Xem SKU →</a></td></tr>
                    @empty
                        <tr><td class="px-4 py-3"><span class="block font-semibold">{{ $variant->variant_name ?: 'Mặc định' }}</span><span class="break-all font-mono text-xs text-slate-500">{{ $variant->sku }} · {{ $variant->unit?->symbol }}</span></td><td colspan="5" class="px-4 py-3 text-slate-500">Chưa có số dư {{ !empty($filters['warehouse_id']) ? 'tại kho đã lọc' : 'ở kho nào' }}.</td><td class="px-4 py-3"><a href="{{ route('admin.web.inventory.show', $variant) }}" class="font-semibold text-junie-navy hover:underline">Xem SKU →</a></td></tr>
                    @endforelse
                @endforeach
            </tbody></table></div>
        </details>
    @empty
        <x-empty-state title="Không tìm thấy sản phẩm phù hợp." />
    @endforelse
    <x-pagination :paginator="$products" />
</div>
@endsection
