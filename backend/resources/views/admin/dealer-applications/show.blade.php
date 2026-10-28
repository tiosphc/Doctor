@extends('layouts.admin')

@section('title', 'Đơn đăng ký đại lý #'.$application->id)
@section('page-title', 'Xét duyệt đại lý')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    <a href="{{ route('admin.web.dealer-applications.index') }}" class="text-sm font-semibold text-junie-navy underline">← Đơn đăng ký</a>
    <header class="flex flex-wrap items-start justify-between gap-4">
        <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Đơn #{{ $application->id }}</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $application->company_name }}</h1><p class="mt-2 text-sm text-slate-600">Gửi {{ $application->created_at?->format('d/m/Y H:i') }}</p></div>
        <x-badge>{{ ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Từ chối', 'cancelled' => 'Đã hủy'][$application->status] ?? $application->status }}</x-badge>
    </header>
    @if($application->status === 'pending')
        <section class="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:grid-cols-2">
            <div><h2 class="font-semibold text-junie-navy">Duyệt đơn</h2><p class="mt-1 text-sm text-slate-600">Khi duyệt, hệ thống sẽ tạo tài khoản đại lý với Tier ban đầu và ví số dư 0 đ.</p><div class="mt-4"><x-confirm-modal :action="route('admin.web.dealer-applications.approve', $application)" title="Duyệt đơn đăng ký đại lý" :message="'Duyệt đơn #'.$application->id.' của '.$application->company_name.'? Tài khoản đại lý sẽ được tạo theo dữ liệu đã nộp.'" confirm-label="Duyệt đơn" tone="primary" class="inline-flex min-h-11 items-center rounded-lg bg-junie-navy px-4 text-sm font-semibold text-white"><x-slot:trigger>Duyệt đơn</x-slot:trigger></x-confirm-modal></div></div>
            <form method="POST" action="{{ route('admin.web.dealer-applications.reject', $application) }}" x-data="{ submitting: false }" @submit="if (submitting || !window.confirm('Xác nhận từ chối đơn đăng ký đại lý này?')) { $event.preventDefault(); return; } submitting = true" class="space-y-3">@csrf
                <label for="rejection_reason" class="block text-sm font-semibold text-slate-700">Lý do từ chối</label>
                <textarea id="rejection_reason" name="rejection_reason" rows="3" minlength="5" maxlength="1000" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">{{ old('rejection_reason') }}</textarea>
                @error('rejection_reason')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
                <x-button type="submit" variant="danger" x-bind:disabled="submitting">Từ chối đơn</x-button>
            </form>
        </section>
    @endif
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 class="text-lg font-semibold text-junie-navy">Thông tin đã gửi</h2>
        <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
            @foreach(['Người nộp' => $application->user?->name, 'Email người nộp' => $application->user?->email, 'Tên giao dịch' => $application->trading_name, 'Người liên hệ' => $application->contact_name, 'Email liên hệ' => $application->email, 'Điện thoại' => $application->phone, 'Mã số thuế' => $application->tax_code, 'Loại hình' => $application->business_type, 'Địa chỉ' => implode(', ', array_filter([$application->business_address_line1, $application->business_address_line2, $application->city, $application->province, $application->country, $application->postal_code])), 'Dự kiến mua/tháng' => $application->estimated_monthly_purchase === null ? null : number_format((float) $application->estimated_monthly_purchase, 0, ',', '.').' đ', 'Ghi chú' => $application->note, 'Người xét duyệt' => $application->reviewer?->name, 'Ngày xét duyệt' => $application->reviewed_at?->format('d/m/Y H:i'), 'Lý do từ chối' => $application->rejection_reason] as $label => $value)
                @if(filled($value))<div class="min-w-0"><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="mt-1 break-words font-medium text-slate-800">{{ $value }}</dd></div>@endif
            @endforeach
        </dl>
        @if($application->approvedAccount)<a href="{{ route('admin.web.dealers.show', $application->approvedAccount) }}" class="mt-5 inline-block font-semibold text-junie-navy underline">Tài khoản {{ $application->approvedAccount->code }} →</a>@endif
    </section>
    <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Lịch sử xét duyệt</h2>
        @forelse($history as $event)<p class="mt-3 border-t border-slate-100 pt-3 text-sm"><span class="font-semibold">{{ $event->description }}</span> · {{ $event->actor_name ?: 'Hệ thống' }} · {{ $event->created_at?->format('d/m/Y H:i') }}</p>@empty<p class="mt-3 text-sm text-slate-500">Chưa có bản ghi audit.</p>@endforelse
    </section>
</div>
@endsection
