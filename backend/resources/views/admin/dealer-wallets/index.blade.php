@extends('layouts.admin')

@section('title', 'Ví đại lý')
@section('page-title', 'Ví đại lý')

@section('content')
<div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Tài chính đại lý</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Ví đại lý</h1><p class="mt-2 text-sm text-slate-600">Số dư và giao dịch đã ghi sổ. Yêu cầu chuyển khoản chờ Admin kiểm tra riêng.</p></div><div class="flex flex-wrap gap-2"><a href="{{ route('admin.web.dealer-wallets.transactions') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Tất cả giao dịch</a><a href="{{ route('admin.web.dealer-wallet-deposits.index') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Yêu cầu nạp ví</a></div></header>
    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        @foreach(['balance' => 'Tổng số dư ví', 'deposited' => 'Tổng đã nạp', 'spent' => 'Tổng đã chi', 'refunded' => 'Tổng đã hoàn'] as $key => $label)
            <div class="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">{{ $label }}</p><p class="mt-2 text-xl font-semibold tabular-nums text-junie-navy"><x-formatted-number :value="$summary[$key]" currency /></p></div>
        @endforeach
    </div>
    <form method="GET" action="{{ route('admin.web.dealer-wallets.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-sm font-semibold">Tìm đại lý<input name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Mã, tên hoặc điện thoại" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">ID đại lý<input name="dealer_id" type="number" min="1" value="{{ $filters['dealer_id'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Trạng thái<select name="status" class="mt-2 min-h-11 w-full rounded-xl border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach(['active' => 'Hoạt động', 'suspended' => 'Tạm ngưng', 'inactive' => 'Ngừng hoạt động'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.dealer-wallets.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <section class="space-y-3"><h2 class="text-lg font-semibold text-junie-navy">Tài khoản đại lý</h2><p class="text-sm text-slate-500">{{ $accounts->total() }} tài khoản · Trang {{ $accounts->currentPage() }}/{{ $accounts->lastPage() }}</p>
        @if($accounts->isEmpty())<x-empty-state title="Không có ví đại lý phù hợp." />@else
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">@foreach($accounts as $dealer)<a href="{{ route('admin.web.dealer-wallets.show', $dealer) }}" class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:border-junie-gold"><div class="flex items-start justify-between gap-2"><div class="min-w-0"><p class="text-xs font-semibold uppercase text-junie-gold">{{ $dealer->code }} · #{{ $dealer->id }}</p><h3 class="mt-1 break-words font-semibold text-junie-navy">{{ $dealer->legal_name }}</h3></div><x-badge>{{ $dealer->status }}</x-badge></div><p class="mt-3 text-sm text-slate-500">{{ $dealer->currentTier?->name ?? 'Chưa gán Tier' }} · Ví #{{ $dealer->wallet?->id ?? '—' }}</p><p class="mt-2 text-xl font-semibold tabular-nums text-junie-navy"><x-formatted-number :value="$dealer->wallet?->balance ?? '0.00'" currency /></p></a>@endforeach</div><x-pagination :paginator="$accounts" />
        @endif
    </section>
    <section class="space-y-3"><div class="flex flex-wrap items-center justify-between gap-2"><h2 class="text-lg font-semibold text-junie-navy">Giao dịch gần đây</h2><a href="{{ route('admin.web.dealer-wallets.transactions') }}" class="text-sm font-semibold text-junie-navy underline">Xem tất cả →</a></div>@include('admin.dealer-wallets.transactions-table', ['transactions' => $recentTransactions, 'showDealer' => true])</section>
</div>
@endsection
