@extends('layouts.admin')

@section('title', 'Phiếu trả hàng')
@section('page-title', 'Phiếu trả hàng')

@section('content')
<div class="space-y-6">
    <header><h1 class="text-3xl font-semibold text-junie-navy">Phiếu trả hàng</h1><p class="mt-2 text-sm text-slate-600">Theo dõi yêu cầu, kiểm hàng và các khoản hoàn tiền của đơn Retail/Dealer.</p></header>
    <form method="GET" action="{{ route('admin.web.sales.returns.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 lg:grid-cols-4">
        <label class="sm:col-span-2 lg:col-span-4"><span class="mb-1 block text-sm font-medium">Tìm phiếu / đơn / người nhận</span><input name="search" value="{{ $filters['search'] ?? '' }}" maxlength="100" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"></label>
        <label><span class="mb-1 block text-sm font-medium">Trạng thái</span><select name="status" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach (['requested', 'approved', 'rejected', 'pending', 'completed'] as $status)<option value="{{ $status }}" @selected(($filters['status'] ?? '') === $status)>{{ $status }}</option>@endforeach</select></label>
        <label><span class="mb-1 block text-sm font-medium">Kênh</span><select name="sales_channel" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach (['retail', 'dealer'] as $channel)<option value="{{ $channel }}" @selected(($filters['sales_channel'] ?? '') === $channel)>{{ ucfirst($channel) }}</option>@endforeach</select></label>
        <label><span class="mb-1 block text-sm font-medium">Nguồn yêu cầu</span><select name="request_source" class="min-h-11 w-full rounded-xl border border-slate-300 px-3 text-sm"><option value="">Tất cả</option>@foreach (['admin', 'retail', 'dealer'] as $source)<option value="{{ $source }}" @selected(($filters['request_source'] ?? '') === $source)>{{ $source }}</option>@endforeach</select></label>
        <div class="flex items-end gap-2"><button class="min-h-11 rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Lọc</button><a href="{{ route('admin.web.sales.returns.index') }}" class="inline-flex min-h-11 items-center rounded-xl border border-slate-300 px-4 text-sm">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-600">{{ $returns->total() }} phiếu · Trang {{ $returns->currentPage() }}/{{ $returns->lastPage() }}</p>
    @if ($returns->isEmpty())
        <x-empty-state title="Không tìm thấy phiếu trả hàng phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[850px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-slate-600"><tr><th class="p-4">Phiếu</th><th class="p-4">Đơn hàng</th><th class="p-4">Người mua / nhận</th><th class="p-4">Kênh / nguồn</th><th class="p-4">Trạng thái</th><th class="p-4 text-right">Số lượng</th><th class="p-4 text-right">Giá trị ghi nhận</th><th class="p-4">Ngày tạo / duyệt</th></tr></thead><tbody class="divide-y divide-slate-100">
            @foreach ($returns as $entry)
                <tr><td class="p-4"><a class="font-semibold text-junie-navy hover:underline" href="{{ route('admin.web.sales.returns.show', $entry) }}">{{ $entry->return_code }}</a></td><td class="p-4"><a class="text-junie-navy hover:underline" href="{{ route('admin.web.sales.orders.show', $entry->salesOrder) }}">{{ $entry->salesOrder?->order_code }}</a></td><td class="p-4">{{ $entry->salesOrder?->buyer?->name ?? $entry->salesOrder?->dealer_name_snapshot ?? $entry->salesOrder?->recipient_name ?? '—' }}</td><td class="p-4">{{ $entry->salesOrder?->sales_channel }} · {{ $entry->request_source }}</td><td class="p-4"><x-badge :tone="$entry->status === 'completed' ? 'success' : 'gold'">{{ $entry->status }}</x-badge></td><td class="p-4 text-right"><x-formatted-number :value="$entry->requested_quantity ?? 0" /></td><td class="p-4 text-right">@if ($entry->status === 'completed')<x-formatted-number :value="$entry->return_value ?? 0" currency />@else — @endif</td><td class="p-4 whitespace-nowrap">{{ $entry->created_at?->format('d/m/Y H:i') }}<br><span class="text-xs text-slate-500">{{ $entry->approved_at?->format('d/m/Y H:i') ?? '—' }}</span></td></tr>
            @endforeach
        </tbody></table></div>
    @endif
    <x-pagination :paginator="$returns" />
</div>
@endsection
