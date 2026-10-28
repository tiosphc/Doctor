@extends('layouts.admin')

@section('title', 'Đơn đăng ký đại lý')
@section('page-title', 'Đơn đăng ký đại lý')

@section('content')
<div class="space-y-6">
    <header>
        <p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Đại lý</p>
        <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Đơn đăng ký đại lý</h1>
        <p class="mt-2 text-sm text-slate-600">Xét duyệt đơn đăng ký và xem tài khoản được tạo từ đơn đã duyệt.</p>
    </header>
    <form method="GET" action="{{ route('admin.web.dealer-applications.index') }}" class="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm sm:grid-cols-2 xl:grid-cols-4">
        <label class="text-sm font-semibold text-slate-700 xl:col-span-2">Tìm theo cơ sở, người nộp, email hoặc điện thoại
            <input name="search" maxlength="100" value="{{ $filters['search'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-700">Trạng thái
            <select name="status" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">
                <option value="">Tất cả</option>
                @foreach(['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'cancelled' => 'Đã hủy'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-semibold text-slate-700">ID người nộp
            <input name="applicant_id" type="number" min="1" value="{{ $filters['applicant_id'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-700">Từ ngày
            <input name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">
        </label>
        <label class="text-sm font-semibold text-slate-700">Đến ngày
            <input name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="mt-2 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal">
        </label>
        <div class="flex flex-wrap items-end gap-2 sm:col-span-2"><x-button type="submit">Lọc</x-button><a href="{{ route('admin.web.dealer-applications.index') }}" class="inline-flex min-h-11 items-center rounded-lg border border-slate-300 px-4 text-sm font-semibold">Xóa lọc</a></div>
    </form>
    <p class="text-sm text-slate-500">{{ $applications->total() }} đơn · Trang {{ $applications->currentPage() }}/{{ $applications->lastPage() }}</p>
    @if($applications->isEmpty())
        <x-empty-state title="Không có đơn đăng ký phù hợp." />
    @else
        <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
            <table class="w-full min-w-[780px] text-left text-sm">
                <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Đơn / người nộp</th><th class="px-4 py-3">Cơ sở</th><th class="px-4 py-3">Điện thoại</th><th class="px-4 py-3">Ngày gửi</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Xét duyệt</th><th class="px-4 py-3">Chi tiết</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($applications as $application)
                        <tr><td class="px-4 py-3"><span class="font-mono text-xs">#{{ $application->id }}</span><p class="font-semibold">{{ $application->user?->name }}</p><p class="break-all text-xs text-slate-500">{{ $application->user?->email }}</p></td><td class="px-4 py-3">{{ $application->company_name }}</td><td class="px-4 py-3">{{ $application->phone }}</td><td class="px-4 py-3">{{ $application->created_at?->format('d/m/Y H:i') }}</td><td class="px-4 py-3">{{ ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'cancelled' => 'Đã hủy'][$application->status] ?? $application->status }}</td><td class="px-4 py-3">{{ $application->approvedAccount?->code ?? ($application->reviewed_at?->format('d/m/Y H:i') ?? '—') }}</td><td class="px-4 py-3"><a href="{{ route('admin.web.dealer-applications.show', $application) }}" class="font-semibold text-junie-navy underline">Xem</a></td></tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <x-pagination :paginator="$applications" />
    @endif
</div>
@endsection
