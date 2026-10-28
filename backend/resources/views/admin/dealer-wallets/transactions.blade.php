@extends('layouts.admin')

@section('title', 'Giao dịch ví đại lý')
@section('page-title', 'Giao dịch ví đại lý')

@section('content')
<div class="space-y-6"><a href="{{ route('admin.web.dealer-wallets.index') }}" class="text-sm font-semibold text-junie-navy underline">← Ví đại lý</a><header><h1 class="text-3xl font-semibold text-junie-navy">Giao dịch ví</h1><p class="mt-2 text-sm text-slate-600">Lịch sử ledger đã ghi sổ, không chỉnh sửa tại đây.</p></header>
    <form method="GET" action="{{ route('admin.web.dealer-wallets.transactions') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-5">
        <label class="text-sm font-semibold">Mã / đại lý<input name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">ID đại lý<input name="dealer_id" type="number" min="1" value="{{ $filters['dealer_id'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Loại<select name="type" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach(['deposit_credit' => 'Nạp tiền', 'order_debit' => 'Thanh toán đơn', 'refund_credit' => 'Hoàn tiền'] as $value => $label)<option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Từ ngày<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Đến ngày<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <div class="flex gap-2 sm:col-span-2 xl:col-span-5"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.dealer-wallets.transactions') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $transactions->total() }} giao dịch · Trang {{ $transactions->currentPage() }}/{{ $transactions->lastPage() }}</p>
    @include('admin.dealer-wallets.transactions-table', ['showDealer' => true])
    <x-pagination :paginator="$transactions" />
</div>
@endsection
