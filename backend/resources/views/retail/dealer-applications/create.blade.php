@extends('layouts.customer')

@section('title', 'Đăng ký đại lý')

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <header>
            <p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Đại lý Junie</p>
            <h1 class="mt-2 text-3xl font-semibold text-junie-navy">Đăng ký đại lý</h1>
            <p class="mt-2 text-sm text-slate-600">Gửi thông tin cơ sở để quản trị viên xét duyệt. Tài khoản mua Retail của bạn vẫn hoạt động bình thường.</p>
        </header>

        @if ($application)
            <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Trạng thái đơn gần nhất">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-lg font-semibold text-junie-navy">Đơn gần nhất #{{ $application->id }}</h2>
                        <p class="mt-1 text-sm text-slate-600">{{ $application->company_name }} · Gửi {{ $application->created_at?->format('d/m/Y H:i') }}</p>
                    </div>
                    <x-badge :tone="$application->status === 'approved' ? 'success' : 'gold'">{{ ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối', 'cancelled' => 'Đã hủy'][$application->status] ?? $application->status }}</x-badge>
                </div>
                @if ($application->status === 'rejected' && $application->rejection_reason)
                    <p class="mt-3 whitespace-pre-line text-sm text-red-700">Lý do từ chối: {{ $application->rejection_reason }}</p>
                @endif
                <a href="{{ route('retail.web.dealer-applications.show', $application) }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Xem chi tiết đơn →</a>
            </section>
        @endif

        @if ($portalAvailable)
            <a href="{{ route('dealer.web.orders.index') }}" class="inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Vào khu vực đại lý</a>
        @endif

        @if ($canApply)
            <form method="POST" action="{{ route('retail.web.dealer-applications.store') }}" class="space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7" x-data="{ submitting: false }" @submit="if (submitting) { $event.preventDefault(); return; } submitting = true">
                @csrf
                <div>
                    <h2 class="text-xl font-semibold text-junie-navy">Thông tin cơ sở</h2>
                    <p class="mt-1 text-sm text-slate-600">Các trường có dấu * là bắt buộc. Số điện thoại cần đúng định dạng quốc tế; số Việt Nam bắt đầu bằng 0 sẽ được chuẩn hóa.</p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    @foreach ([
                        ['company_name', 'Tên cơ sở / doanh nghiệp', 'text', true, 'organization'],
                        ['trading_name', 'Tên giao dịch', 'text', false, 'organization'],
                        ['contact_name', 'Người liên hệ', 'text', true, 'name'],
                        ['email', 'Email liên hệ', 'email', true, 'email'],
                        ['phone', 'Số điện thoại', 'tel', true, 'tel'],
                        ['tax_code', 'Mã số thuế', 'text', false, 'off'],
                        ['business_address_line1', 'Địa chỉ', 'text', true, 'street-address'],
                        ['business_address_line2', 'Địa chỉ bổ sung', 'text', false, 'address-line2'],
                        ['city', 'Thành phố / quận huyện', 'text', true, 'address-level2'],
                        ['province', 'Tỉnh / thành', 'text', true, 'address-level1'],
                        ['country', 'Quốc gia', 'text', true, 'country'],
                        ['postal_code', 'Mã bưu chính', 'text', false, 'postal-code'],
                        ['business_type', 'Loại hình kinh doanh', 'text', false, 'off'],
                    ] as [$field, $label, $type, $required, $autocomplete])
                        <div>
                            <label for="{{ $field }}" class="block text-sm font-semibold text-slate-700">{{ $label }}@if ($required) <span aria-hidden="true">*</span>@endif</label>
                            <input id="{{ $field }}" name="{{ $field }}" type="{{ $type }}" autocomplete="{{ $autocomplete }}" value="{{ old($field, match ($field) { 'contact_name' => auth()->user()->name, 'email' => auth()->user()->email, 'phone' => auth()->user()->phone ?? '', 'country' => 'VN', default => '' }) }}" @if ($required) required @endif
                                @if ($field === 'phone') maxlength="32" @elseif ($field === 'tax_code') maxlength="50" @elseif ($field === 'postal_code') maxlength="30" @elseif ($field === 'business_type') maxlength="80" @elseif (in_array($field, ['city', 'province', 'country'], true)) maxlength="120" @else maxlength="255" @endif
                                @error($field) aria-invalid="true" aria-describedby="{{ $field }}-error" @enderror
                                class="mt-1 min-h-11 w-full rounded-xl border px-3 text-sm outline-none focus:border-junie-navy focus:ring-2 focus:ring-blue-100 @error($field) border-red-500 @else border-slate-300 @enderror">
                            @error($field) <p id="{{ $field }}-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                        </div>
                    @endforeach
                    <div>
                        <label for="estimated_monthly_purchase" class="block text-sm font-semibold text-slate-700">Dự kiến mua hàng mỗi tháng (VND)</label>
                        <input id="estimated_monthly_purchase" name="estimated_monthly_purchase" type="number" min="0" step="0.01" value="{{ old('estimated_monthly_purchase') }}" @error('estimated_monthly_purchase') aria-invalid="true" aria-describedby="estimated_monthly_purchase-error" @enderror class="mt-1 min-h-11 w-full rounded-xl border px-3 text-sm @error('estimated_monthly_purchase') border-red-500 @else border-slate-300 @enderror">
                        @error('estimated_monthly_purchase') <p id="estimated_monthly_purchase-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2">
                        <label for="note" class="block text-sm font-semibold text-slate-700">Ghi chú</label>
                        <textarea id="note" name="note" rows="3" maxlength="2000" @error('note') aria-invalid="true" aria-describedby="note-error" @enderror class="mt-1 w-full rounded-xl border px-3 py-2 text-sm @error('note') border-red-500 @else border-slate-300 @enderror">{{ old('note') }}</textarea>
                        @error('note') <p id="note-error" class="mt-1 text-sm text-red-700">{{ $message }}</p> @enderror
                    </div>
                </div>
                <x-button type="submit" x-bind:disabled="submitting">Gửi đơn đăng ký</x-button>
            </form>
        @elseif (! $portalAvailable)
            <p class="rounded-2xl border border-slate-200 bg-white p-5 text-sm text-slate-600">Bạn chưa thể gửi đơn mới. Vui lòng xem trạng thái đơn gần nhất hoặc liên hệ quản trị viên nếu cần hỗ trợ.</p>
        @endif
    </div>
@endsection
