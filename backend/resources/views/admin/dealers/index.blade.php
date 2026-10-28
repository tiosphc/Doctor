@extends('layouts.admin')

@section('title', 'Tài khoản đại lý')
@section('page-title', 'Tài khoản đại lý')

@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Đại lý</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Tài khoản đại lý</h1><p class="mt-2 text-sm text-slate-600">Tài khoản được tạo từ đơn đăng ký đã duyệt; lịch sử membership, Tier và đơn hàng được giữ nguyên.</p></header>
    <form method="GET" action="{{ route('admin.web.dealers.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-4">
        <label class="text-sm font-semibold text-slate-700">Mã, tên hoặc điện thoại<input name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold text-slate-700">Trạng thái<select name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach(['active' => 'Hoạt động', 'suspended' => 'Tạm ngưng', 'inactive' => 'Ngừng hoạt động'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold text-slate-700">Tier cơ sở<select name="tier_id" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach($tiers as $tier)<option value="{{ $tier->id }}" @selected((string) ($filters['tier_id'] ?? '') === (string) $tier->id)>{{ $tier->name }}</option>@endforeach</select></label>
        <div class="flex flex-wrap items-end gap-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.dealers.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $dealers->total() }} đại lý · Trang {{ $dealers->currentPage() }}/{{ $dealers->lastPage() }}</p>
    @if($dealers->isEmpty())<x-empty-state title="Không có đại lý phù hợp." />@else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[950px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Mã / tên</th><th class="px-4 py-3">Owner</th><th class="px-4 py-3">Liên hệ</th><th class="px-4 py-3">Tier</th><th class="px-4 py-3">Thành viên</th><th class="px-4 py-3 text-right">Số dư ví</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Ngày tạo</th></tr></thead><tbody class="divide-y divide-slate-100">
            @foreach($dealers as $dealer)<tr><td class="px-4 py-3"><a href="{{ route('admin.web.dealers.show', $dealer) }}" class="font-semibold text-junie-navy underline">{{ $dealer->code }}</a><p>{{ $dealer->legal_name }}</p></td><td class="px-4 py-3">{{ $dealer->memberships->first()?->user?->name ?? '—' }}</td><td class="px-4 py-3">{{ $dealer->phone }}</td><td class="px-4 py-3">{{ $dealer->currentTier?->name ?? 'Chưa gán' }}</td><td class="px-4 py-3">{{ $dealer->memberships_count }}</td><td class="px-4 py-3 text-right tabular-nums">{{ number_format((float) ($dealer->wallet?->balance ?? 0), 0, ',', '.') }} đ</td><td class="px-4 py-3">{{ ['active' => 'Hoạt động', 'suspended' => 'Tạm ngưng', 'inactive' => 'Ngừng hoạt động'][$dealer->status] ?? $dealer->status }}</td><td class="px-4 py-3">{{ $dealer->created_at?->format('d/m/Y') }}</td></tr>@endforeach
        </tbody></table></div><x-pagination :paginator="$dealers" />
    @endif
</div>
@endsection
