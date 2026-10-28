@extends('layouts.admin')

@section('title', 'Vùng phục vụ kho')
@section('page-title', 'Vùng phục vụ kho')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-3xl font-semibold text-junie-navy">Vùng phục vụ kho</h1>
            <p class="mt-2 text-sm text-slate-600">Kho có độ ưu tiên nhỏ hơn được xét trước trong cùng tỉnh. Nếu bằng nhau, hệ thống xét theo mã ID kho.</p>
        </div>
        <a href="{{ route('admin.web.warehouse-service-areas.create') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Thêm vùng phục vụ</a>
    </header>

    <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Chỉ kho đang hoạt động được chọn khi phân bổ đơn mới. Thay đổi độ ưu tiên không chuyển kho của đơn đã tạo.</div>

    <form method="GET" action="{{ route('admin.web.warehouse-service-areas.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-4" aria-label="Lọc vùng phục vụ kho">
        <label><span class="mb-1 block text-sm font-medium">Tìm tên hoặc mã</span><input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
        <label><span class="mb-1 block text-sm font-medium">Kho</span><select name="warehouse_id" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach ($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }} ({{ $warehouse->code }})</option>@endforeach</select></label>
        <label><span class="mb-1 block text-sm font-medium">Tỉnh / thành</span><select name="province_code" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach ($provinces as $province)<option value="{{ $province->code }}" @selected(($filters['province_code'] ?? '') === $province->code)>{{ $province->name }} ({{ $province->code }})</option>@endforeach</select></label>
        <div class="flex flex-wrap items-end gap-2"><button class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Lọc</button><a href="{{ route('admin.web.warehouse-service-areas.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>

    <p class="text-sm text-slate-600">{{ $areas->total() }} vùng · Trang {{ $areas->currentPage() }}/{{ $areas->lastPage() }}</p>
    @if ($areas->isEmpty())
        <x-empty-state title="Chưa có vùng phục vụ phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[680px] text-left text-sm">
                <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Tỉnh / thành</th><th class="px-4 py-3">Kho</th><th class="px-4 py-3">Trạng thái kho</th><th class="px-4 py-3">Ưu tiên</th><th class="px-4 py-3">Thao tác</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($areas as $area)
                        <tr>
                            <td class="px-4 py-3"><span class="font-semibold">{{ $area->province?->name ?? $area->province_code }}</span><span class="block font-mono text-xs text-slate-500">{{ $area->province_code }}</span></td>
                            <td class="px-4 py-3"><span class="font-semibold">{{ $area->warehouse?->name ?? '—' }}</span><span class="block font-mono text-xs text-slate-500">{{ $area->warehouse?->code }}</span></td>
                            <td class="px-4 py-3"><x-product-status :status="$area->warehouse?->status ?? 'inactive'" /></td>
                            <td class="px-4 py-3">{{ $area->priority }}</td>
                            <td class="px-4 py-3"><a href="{{ route('admin.web.warehouse-service-areas.edit', $area) }}" class="inline-flex min-h-11 items-center font-semibold text-junie-navy underline underline-offset-4">Sửa ưu tiên</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-pagination :paginator="$areas" />
    @endif
</div>
@endsection
