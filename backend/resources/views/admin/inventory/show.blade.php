@extends('layouts.admin')

@section('title', 'Tồn kho · '.$variant->sku)
@section('page-title', 'Chi tiết tồn kho')

@section('content')
<div class="space-y-6">
    <a href="{{ route('admin.web.inventory.index', ['product_id' => $variant->product_id]) }}" class="text-sm font-semibold text-junie-navy hover:underline">← Tồn kho của sản phẩm</a>
    <header><p class="text-sm text-slate-500">{{ $variant->product->product_code }} · {{ $variant->product->name }}</p><h1 class="mt-2 break-all text-3xl font-semibold text-junie-navy">{{ $variant->sku }}</h1><p class="mt-2 text-sm text-slate-600">{{ $variant->variant_name }} · {{ $variant->unit?->name }} ({{ $variant->unit?->symbol }}) · {{ $variant->status === 'active' && $variant->track_inventory ? 'Theo dõi tồn' : 'Không hoạt động / không theo dõi tồn' }}</p></header>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-xl font-semibold text-junie-navy">Số dư theo kho</h2><div class="mt-4 overflow-x-auto"><table class="w-full min-w-[760px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-3 py-3">Kho</th><th class="px-3 py-3">Tồn thực tế</th><th class="px-3 py-3">Đã giữ</th><th class="px-3 py-3">Khả dụng</th><th class="px-3 py-3">Trạng thái</th><th class="px-3 py-3">Thao tác</th></tr></thead><tbody class="divide-y divide-slate-100">
        @foreach($warehouses as $warehouse)
            @php($balance = $balances->get($warehouse->id))
            <tr><td class="px-3 py-3 font-semibold">{{ $warehouse->code }} · {{ $warehouse->name }}@if($warehouse->status !== 'active')<span class="block text-xs font-normal text-slate-500">Kho ngừng hoạt động</span>@endif</td><td class="px-3 py-3 tabular-nums">@if($balance)<x-formatted-number :value="$balance->on_hand_quantity" />@else 0 @endif</td><td class="px-3 py-3 tabular-nums">@if($balance)<x-formatted-number :value="$balance->reserved_quantity" />@else 0 @endif</td><td class="px-3 py-3 tabular-nums font-semibold">@if($balance)<x-formatted-number :value="$balance->available_quantity" />@else 0 @endif</td><td class="px-3 py-3">@if($balance && $inventory->isLowStock($balance, $variant))<x-badge tone="gold">Tồn thấp</x-badge>@elseif($balance)<x-badge tone="success">Đã ghi nhận</x-badge>@else<span class="text-slate-500">Chưa phát sinh</span>@endif</td><td class="px-3 py-3"><div class="flex flex-wrap gap-3 text-xs font-semibold">@if($warehouse->status === 'active' && $variant->status === 'active' && $variant->track_inventory && $variant->product->status !== 'inactive')<a class="text-junie-navy hover:underline" href="{{ route('admin.web.inventory.operation', [$variant, $warehouse, 'opening']) }}">Tồn đầu kỳ</a><a class="text-junie-navy hover:underline" href="{{ route('admin.web.inventory.operation', [$variant, $warehouse, 'adjustment']) }}">Điều chỉnh</a><a class="text-junie-navy hover:underline" href="{{ route('admin.web.inventory.operation', [$variant, $warehouse, 'receipt']) }}">Nhập thủ công</a>@else<span class="text-slate-500">Chỉ xem</span>@endif</div></td></tr>
        @endforeach
    </tbody></table></div></section>
    <section class="space-y-4"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-xl font-semibold text-junie-navy">Lịch sử biến động</h2><a href="{{ route('admin.web.inventory.movements') }}" class="text-sm font-semibold text-junie-navy hover:underline">Lịch sử toàn kho →</a></div>
        <form method="GET" action="{{ route('admin.web.inventory.show', $variant) }}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
            <label class="text-sm">Kho<select name="warehouse_id" class="mt-1 w-full rounded-lg border border-slate-300 p-2"><option value="">Tất cả</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)($filters['warehouse_id'] ?? '') === (string)$warehouse->id)>{{ $warehouse->code }}</option>@endforeach</select></label>
            <label class="text-sm">Loại<select name="movement_type" class="mt-1 w-full rounded-lg border border-slate-300 p-2"><option value="">Tất cả</option>@foreach($movementTypes as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></label>
            <label class="text-sm">Từ ngày<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-lg border border-slate-300 p-2"></label><label class="text-sm">Đến ngày<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-lg border border-slate-300 p-2"></label>
            <label class="text-sm">Tham chiếu<input name="reference" value="{{ $filters['reference'] ?? '' }}" maxlength="100" class="mt-1 w-full rounded-lg border border-slate-300 p-2"></label>
            <div class="flex gap-3 sm:col-span-2 lg:col-span-5"><x-button type="submit">Lọc lịch sử</x-button><a href="{{ route('admin.web.inventory.show', $variant) }}" class="self-center text-sm font-semibold text-junie-navy">Xóa lọc</a></div>
        </form>
        @include('admin.inventory._movements')
    </section>
</div>
@endsection
