@extends('layouts.admin')

@section('title', 'Nhật ký #'.$auditLog->id)
@section('page-title', 'Chi tiết nhật ký')

@section('content')
<div class="space-y-6">
    <a href="{{ route('admin.web.audit-logs.index') }}" class="text-sm font-semibold text-junie-navy underline underline-offset-2">← Danh sách nhật ký</a>
    <header><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Nhật ký hoạt động</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $auditLog->action }} · {{ $auditLog->module }}</h1><p class="mt-2 text-sm text-slate-600">Bản ghi #{{ $auditLog->id }} · {{ $auditLog->created_at?->format('d/m/Y H:i:s') }}</p></header>
    <section class="grid gap-4 lg:grid-cols-2"><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Người thực hiện</h2><dl class="mt-3 space-y-2 text-sm"><div><dt class="text-slate-500">Tên</dt><dd class="font-medium">{{ $auditLog->actor_name }}</dd></div><div><dt class="text-slate-500">Vai trò</dt><dd>{{ $auditLog->actor_role }}</dd></div></dl></div><div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Đối tượng</h2><dl class="mt-3 space-y-2 text-sm"><div><dt class="text-slate-500">Tên</dt><dd class="break-words font-medium">{{ $auditLog->target_name ?? '—' }}</dd></div><div><dt class="text-slate-500">Loại / ID</dt><dd>{{ $auditLog->target_type }} #{{ $auditLog->target_id ?? '—' }}</dd></div></dl></div></section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Mô tả</h2><p class="mt-3 whitespace-pre-wrap break-words text-sm">{{ $auditLog->description }}</p></section>
    <div class="grid min-w-0 gap-4 lg:grid-cols-2">
        <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Giá trị trước</h2>@if ($oldValues === [])<p class="mt-3 text-sm text-slate-500">Không có dữ liệu được phép hiển thị.</p>@else<pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($oldValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif</section>
        <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Giá trị sau</h2>@if ($newValues === [])<p class="mt-3 text-sm text-slate-500">Không có dữ liệu được phép hiển thị.</p>@else<pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($newValues, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif</section>
    </div>
    <section class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Metadata được phép hiển thị</h2>@if ($metadata === [])<p class="mt-3 text-sm text-slate-500">Không có dữ liệu được phép hiển thị.</p>@else<pre class="mt-3 max-h-96 overflow-auto whitespace-pre-wrap break-all rounded-lg bg-slate-50 p-3 text-xs">{{ json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>@endif</section>
</div>
@endsection
