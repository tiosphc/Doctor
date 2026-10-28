@extends('layouts.admin')

@section('title', 'Nhật ký hoạt động')
@section('page-title', 'Nhật ký hoạt động')

@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Hệ thống & tiện ích</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Nhật ký hoạt động</h1><p class="mt-2 text-sm text-slate-600">Theo dõi thao tác đã ghi nhận. Nhật ký chỉ để xem và không hiển thị thông tin xác thực.</p></header>
    <form method="GET" action="{{ route('admin.web.audit-logs.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-4" aria-label="Lọc nhật ký">
        <label class="text-sm font-semibold">Tìm kiếm<input type="search" name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" placeholder="Actor, đối tượng, mô tả" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Module<select name="module" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach ($modules as $module)<option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ $module }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Hành động<select name="action" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach ($actions as $action)<option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>{{ $action }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">Vai trò<select name="role" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"><option value="">Tất cả</option>@foreach ($roles as $role)<option value="{{ $role }}" @selected(($filters['role'] ?? '') === $role)>{{ $role }}</option>@endforeach</select></label>
        <label class="text-sm font-semibold">ID người thực hiện<input type="number" name="actor_id" min="1" value="{{ $filters['actor_id'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Từ ngày<input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"></label>
        <label class="text-sm font-semibold">Đến ngày<input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"></label>
        <div class="flex flex-wrap items-end gap-2"><button type="submit" class="min-h-11 rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white">Lọc</button><a href="{{ route('admin.web.audit-logs.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-600">{{ $logs->total() }} bản ghi · Trang {{ $logs->currentPage() }}/{{ $logs->lastPage() }}</p>
    @if ($logs->isEmpty())<x-empty-state title="Không có nhật ký phù hợp." />@else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[820px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Thời gian</th><th class="px-4 py-3">Người thực hiện</th><th class="px-4 py-3">Module</th><th class="px-4 py-3">Hành động</th><th class="px-4 py-3">Đối tượng</th><th class="px-4 py-3">Chi tiết</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($logs as $log)<tr><td class="px-4 py-3 whitespace-nowrap">{{ $log->created_at?->format('d/m/Y H:i:s') }}</td><td class="px-4 py-3">{{ $log->actor_name }}<p class="text-xs text-slate-500">{{ $log->actor_role }}</p></td><td class="px-4 py-3">{{ $log->module }}</td><td class="px-4 py-3">{{ $log->action }}</td><td class="px-4 py-3"><p class="max-w-48 truncate">{{ $log->target_name ?? $log->target_type }}</p><p class="text-xs text-slate-500">{{ $log->target_type }} #{{ $log->target_id ?? '—' }}</p></td><td class="px-4 py-3"><a href="{{ route('admin.web.audit-logs.show', $log) }}" class="font-semibold text-junie-navy underline underline-offset-2">Xem</a></td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$logs" />
    @endif
</div>
@endsection
