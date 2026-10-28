@extends('layouts.admin')

@section('title', $dealer->code.' · '.$dealer->legal_name)
@section('page-title', 'Chi tiết đại lý')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div class="flex flex-wrap gap-4 text-sm font-semibold text-junie-navy"><a href="{{ route('admin.web.dealers.index') }}" class="underline">← Tài khoản đại lý</a><a href="{{ route('admin.web.dealer-wallets.show', $dealer) }}" class="underline">Xem ví đại lý →</a></div>
    <header class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">{{ $dealer->code }}</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $dealer->legal_name }}</h1><p class="mt-2 text-sm text-slate-600">{{ ['active' => 'Hoạt động', 'suspended' => 'Tạm ngưng', 'inactive' => 'Ngừng hoạt động'][$dealer->status] ?? $dealer->status }} · {{ $orderCount }} đơn hàng · Ví {{ number_format((float) ($dealer->wallet?->balance ?? 0), 0, ',', '.') }} đ (chỉ xem)</p></div>
        <div class="flex flex-wrap gap-2">
            @if($dealer->status === 'active')
                <x-confirm-modal :action="route('admin.web.dealers.suspend', $dealer)" title="Tạm ngưng đại lý" :message="'Tạm ngưng '.$dealer->code.'? Quyền đặt đơn mới sẽ bị chặn theo trạng thái tài khoản.'" confirm-label="Tạm ngưng" class="rounded-lg border border-amber-400 px-4 py-2 text-sm font-semibold text-amber-800"><x-slot:trigger>Tạm ngưng</x-slot:trigger></x-confirm-modal>
                <x-confirm-modal :action="route('admin.web.dealers.inactivate', $dealer)" title="Ngừng hoạt động đại lý" :message="'Ngừng hoạt động '.$dealer->code.'? Lịch sử đơn hàng và membership vẫn được giữ.'" confirm-label="Ngừng hoạt động" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700"><x-slot:trigger>Ngừng hoạt động</x-slot:trigger></x-confirm-modal>
            @elseif($dealer->status === 'suspended')
                <x-confirm-modal :action="route('admin.web.dealers.activate', $dealer)" title="Kích hoạt đại lý" :message="'Kích hoạt lại '.$dealer->code.'? Hệ thống sẽ dùng membership và Tier hiện có.'" confirm-label="Kích hoạt" tone="primary" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold"><x-slot:trigger>Kích hoạt</x-slot:trigger></x-confirm-modal>
                <x-confirm-modal :action="route('admin.web.dealers.inactivate', $dealer)" title="Ngừng hoạt động đại lý" :message="'Ngừng hoạt động '.$dealer->code.'? Lịch sử vẫn được giữ.'" confirm-label="Ngừng hoạt động" class="rounded-lg border border-red-300 px-4 py-2 text-sm font-semibold text-red-700"><x-slot:trigger>Ngừng hoạt động</x-slot:trigger></x-confirm-modal>
            @else
                <x-confirm-modal :action="route('admin.web.dealers.activate', $dealer)" title="Kích hoạt đại lý" :message="'Kích hoạt lại '.$dealer->code.'? Kiểm tra membership và Tier trước khi tiếp tục.'" confirm-label="Kích hoạt" tone="primary" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold"><x-slot:trigger>Kích hoạt</x-slot:trigger></x-confirm-modal>
            @endif
        </div>
    </header>
    <div class="grid gap-5 lg:grid-cols-2">
        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Thông tin doanh nghiệp</h2>
            <form method="POST" action="{{ route('admin.web.dealers.update', $dealer) }}" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true" class="mt-4 grid gap-3 sm:grid-cols-2">@csrf @method('PATCH')
                @foreach(['legal_name' => 'Tên cơ sở', 'trading_name' => 'Tên giao dịch', 'contact_name' => 'Người liên hệ', 'email' => 'Email', 'phone' => 'Điện thoại', 'tax_code' => 'Mã số thuế', 'billing_address_line1' => 'Địa chỉ', 'billing_address_line2' => 'Địa chỉ bổ sung', 'city' => 'Thành phố / quận huyện', 'province' => 'Tỉnh / thành', 'country' => 'Quốc gia', 'postal_code' => 'Mã bưu chính'] as $field => $label)
                    <label class="min-w-0 text-sm font-semibold text-slate-700">{{ $label }}<input name="{{ $field }}" value="{{ old($field, $dealer->$field) }}" @if(in_array($field, ['legal_name', 'contact_name', 'email', 'phone', 'billing_address_line1', 'city', 'province', 'country'], true)) required @endif class="mt-1 min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal"></label>
                    @error($field)<p class="text-sm text-red-700 sm:col-span-2">{{ $message }}</p>@enderror
                @endforeach
                <p class="text-xs text-slate-500 sm:col-span-2">Mã đại lý không đổi. Lưu thông tin không thay đổi người sở hữu, đơn hàng hoặc số dư ví.</p>
                <div class="sm:col-span-2"><x-button type="submit" x-bind:disabled="submitting">Lưu thông tin</x-button></div>
            </form>
        </section>
        <div class="space-y-5">
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Thành viên đại lý</h2><p class="mt-1 text-xs text-slate-500">Danh sách thành viên hiện có, chỉ xem.</p>
                <div class="mt-3 space-y-3">@forelse($memberships as $member)<div class="rounded-lg border border-slate-100 p-3 text-sm"><p class="font-semibold">{{ $member->user?->name ?? 'User #'.$member->user_id }} · {{ $member->membership_role }}</p><p class="break-all text-slate-500">{{ $member->user?->email }} · {{ $member->status }}</p><p class="text-xs text-slate-500">Kích hoạt {{ $member->activated_at?->format('d/m/Y H:i') ?? '—' }}</p></div>@empty<p class="text-sm text-slate-500">Chưa có membership.</p>@endforelse</div><x-pagination :paginator="$memberships" />
            </section>
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Nguồn và trạng thái</h2><dl class="mt-3 space-y-2 text-sm"><div><dt class="text-slate-500">Đơn đăng ký</dt><dd>@if($dealer->application)<a href="{{ route('admin.web.dealer-applications.show', $dealer->application) }}" class="font-semibold text-junie-navy underline">#{{ $dealer->source_application_id }} · {{ $dealer->application->company_name }}</a>@else — @endif</dd></div><div><dt class="text-slate-500">Ngày tạo</dt><dd>{{ $dealer->created_at?->format('d/m/Y H:i') }}</dd></div><div><dt class="text-slate-500">Kích hoạt gần nhất</dt><dd>{{ $dealer->activated_at?->format('d/m/Y H:i') ?? '—' }}</dd></div></dl></section>
        </div>
    </div>
    @include('admin.dealers.tier')
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Lịch sử tài khoản</h2>@forelse($history as $event)<p class="mt-3 border-t border-slate-100 pt-3 text-sm">{{ $event->description }} · {{ $event->actor_name ?: 'Hệ thống' }} · {{ $event->created_at?->format('d/m/Y H:i') }}</p>@empty<p class="mt-3 text-sm text-slate-500">Chưa có bản ghi audit.</p>@endforelse</section>
</div>
@endsection
