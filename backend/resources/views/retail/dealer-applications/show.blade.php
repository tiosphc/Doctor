@extends('layouts.customer')

@section('title', 'Đơn đăng ký đại lý #'.$application->id)

@section('content')
    <div class="mx-auto max-w-4xl space-y-6">
        <a href="{{ route('retail.web.dealer-applications.create') }}" class="text-sm font-semibold text-junie-navy hover:underline">← Đăng ký đại lý</a>
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-widest text-junie-gold">Đơn đăng ký #{{ $application->id }}</p>
                <h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $application->company_name }}</h1>
                <p class="mt-2 text-sm text-slate-600">Gửi lúc {{ $application->created_at?->format('d/m/Y H:i') }} · Cập nhật {{ $application->updated_at?->format('d/m/Y H:i') }}</p>
            </div>
            <x-badge :tone="$application->status === 'approved' ? 'success' : 'gold'">{{ ['pending' => 'Chờ duyệt', 'approved' => 'Đã duyệt', 'rejected' => 'Đã từ chối', 'cancelled' => 'Đã hủy'][$application->status] ?? $application->status }}</x-badge>
        </header>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm" aria-label="Kết quả xét duyệt">
            @if ($application->status === 'pending')
                <p class="text-sm text-slate-700">Đơn đang chờ quản trị viên xét duyệt. Bạn vẫn có thể mua hàng Retail.</p>
            @elseif ($application->status === 'approved')
                <p class="text-sm text-slate-700">Đơn đã được duyệt {{ $application->reviewed_at?->format('d/m/Y H:i') }}.</p>
                @if ($portalAvailable)
                    <a href="{{ route('dealer.web.orders.index') }}" class="mt-4 inline-flex min-h-11 items-center rounded-xl bg-junie-navy px-4 text-sm font-semibold text-white">Vào khu vực đại lý</a>
                @endif
            @elseif ($application->status === 'rejected')
                <p class="text-sm text-slate-700">Đơn đã bị từ chối {{ $application->reviewed_at?->format('d/m/Y H:i') }}.</p>
                @if ($application->rejection_reason)
                    <p class="mt-3 whitespace-pre-line text-sm text-red-700">Lý do: {{ $application->rejection_reason }}</p>
                @endif
                <a href="{{ route('retail.web.dealer-applications.create') }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Xem khả năng đăng ký lại →</a>
            @else
                <p class="text-sm text-slate-700">Đơn đăng ký đã được hủy.</p>
                <a href="{{ route('retail.web.dealer-applications.create') }}" class="mt-4 inline-flex min-h-11 items-center text-sm font-semibold text-junie-navy hover:underline">Xem khả năng đăng ký lại →</a>
            @endif
        </section>

        <section class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <h2 class="text-lg font-semibold text-junie-navy">Thông tin đã gửi</h2>
            <dl class="mt-4 grid gap-4 text-sm sm:grid-cols-2">
                @foreach ([
                    'Tên giao dịch' => $application->trading_name,
                    'Người liên hệ' => $application->contact_name,
                    'Email liên hệ' => $application->email,
                    'Số điện thoại' => $application->phone,
                    'Mã số thuế' => $application->tax_code,
                    'Địa chỉ' => implode(', ', array_filter([$application->business_address_line1, $application->business_address_line2, $application->city, $application->province, $application->country, $application->postal_code])),
                    'Loại hình kinh doanh' => $application->business_type,
                    'Dự kiến mua hàng mỗi tháng' => $application->estimated_monthly_purchase === null ? null : number_format((float) $application->estimated_monthly_purchase, 0, ',', '.').' đ',
                    'Ghi chú' => $application->note,
                ] as $label => $value)
                    @if (filled($value))
                        <div class="min-w-0">
                            <dt class="text-xs text-slate-500">{{ $label }}</dt>
                            <dd class="mt-1 break-words whitespace-pre-line font-medium text-slate-800">{{ $value }}</dd>
                        </div>
                    @endif
                @endforeach
            </dl>
        </section>
    </div>
@endsection
