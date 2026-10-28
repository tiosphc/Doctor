@extends('layouts.admin')

@section('title', 'Khách hàng')
@section('page-title', 'Khách hàng')

@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Quản lý bán hàng</p>
        <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Khách hàng</h1>
        <p class="mt-2 text-sm text-slate-600">Tra cứu hồ sơ khách hàng và lịch sử mua hàng. Dữ liệu trên trang này chỉ để xem.</p>
    </header>

    <form method="GET" action="{{ route('admin.web.customers.index') }}" class="flex flex-wrap items-end gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
        <label class="min-w-64 flex-1 text-sm font-semibold text-slate-700">Tìm khách hàng
            <input name="search" type="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Mã, tên, email hoặc số điện thoại" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">
        </label>
        <button type="submit" class="min-h-11 rounded-lg bg-junie-navy px-5 text-sm font-semibold text-white">Tìm kiếm</button>
        <a href="{{ route('admin.web.customers.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold text-junie-navy">Xóa lọc</a>
    </form>

    <p class="text-sm text-slate-600">{{ $customers->total() }} khách hàng · Trang {{ $customers->currentPage() }}/{{ $customers->lastPage() }}</p>
    @if ($customers->isEmpty())
        <x-empty-state title="Không tìm thấy khách hàng phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[780px] text-left text-sm">
                <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Mã / tên</th><th class="px-4 py-3">Liên hệ</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3 text-right">Lượt khám hoàn tất</th><th class="px-4 py-3">Lịch hẹn gần nhất</th><th class="px-4 py-3">Thao tác</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($customers as $customer)
                        <tr>
                            <td class="px-4 py-3"><a href="{{ route('admin.web.customers.show', $customer) }}" class="font-semibold text-junie-navy underline underline-offset-2">{{ $customer->customer_code ?? '#'.$customer->id }}</a><p>{{ $customer->name }}</p></td>
                            <td class="px-4 py-3"><p>{{ $customer->primary_email ?? '—' }}</p><p class="text-slate-500">{{ $customer->primary_phone ?? '—' }}</p></td>
                            <td class="px-4 py-3"><x-badge :tone="$customer->status === 'active' ? 'success' : 'gold'">{{ $customer->status }}</x-badge></td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ $customer->completed_visits }}</td>
                            <td class="px-4 py-3">{{ $customer->last_appointment_date ?? '—' }}</td>
                            <td class="px-4 py-3"><a href="{{ route('admin.web.customers.show', $customer) }}" class="inline-flex min-h-10 items-center rounded-lg border border-junie-navy px-3 font-semibold text-junie-navy">Xem chi tiết</a></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-pagination :paginator="$customers" />
    @endif
</div>
@endsection
