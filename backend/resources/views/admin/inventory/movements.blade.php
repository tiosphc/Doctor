@extends('layouts.admin')

@section('title', 'Lịch sử biến động kho')
@section('page-title', 'Lịch sử biến động kho')

@section('content')
<div class="space-y-6"><header><a href="{{ route('admin.web.inventory.index') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Tồn kho</a><h1 class="mt-3 text-3xl font-semibold text-junie-navy">Lịch sử biến động kho</h1><p class="mt-2 text-sm text-slate-600">Movement ledger bất biến, gồm nhập, điều chỉnh, xuất đơn hàng và hoàn hàng.</p></header>
    <form method="GET" action="{{ route('admin.web.inventory.movements') }}" class="grid gap-3 rounded-xl border border-slate-200 bg-white p-4 sm:grid-cols-2 lg:grid-cols-5">
        <label class="text-sm">Kho<select name="warehouse_id" class="mt-1 w-full rounded-lg border border-slate-300 p-2"><option value="">Tất cả</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected((string)($filters['warehouse_id'] ?? '') === (string)$warehouse->id)>{{ $warehouse->code }}</option>@endforeach</select></label>
        <label class="text-sm">Loại<select name="movement_type" class="mt-1 w-full rounded-lg border border-slate-300 p-2"><option value="">Tất cả</option>@foreach($movementTypes as $type)<option value="{{ $type }}" @selected(($filters['movement_type'] ?? '') === $type)>{{ $type }}</option>@endforeach</select></label>
        <label class="text-sm">Từ ngày<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-1 w-full rounded-lg border border-slate-300 p-2"></label><label class="text-sm">Đến ngày<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-1 w-full rounded-lg border border-slate-300 p-2"></label>
        <label class="text-sm">Tham chiếu<input name="reference" value="{{ $filters['reference'] ?? '' }}" maxlength="100" class="mt-1 w-full rounded-lg border border-slate-300 p-2"></label>
        <div class="flex gap-3 sm:col-span-2 lg:col-span-5"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.inventory.movements') }}" class="self-center text-sm font-semibold text-junie-navy">Xóa lọc</a></div>
    </form>
    @include('admin.inventory._movements')
</div>
@endsection
