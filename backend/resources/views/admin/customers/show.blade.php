@extends('layouts.admin')

@section('title', $customer->customer_code ?? $customer->name)
@section('page-title', $customer->name)

@section('content')
<div class="space-y-7">
    <a href="{{ route('admin.web.customers.index') }}" class="text-sm font-semibold text-junie-navy underline underline-offset-2">← Danh sách khách hàng</a>
    <header class="flex flex-wrap items-start justify-between gap-3">
        <div><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Hồ sơ khách hàng</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">{{ $customer->name }}</h1><p class="mt-1 text-sm text-slate-500">{{ $customer->customer_code ?? '#'.$customer->id }} · Tạo {{ $customer->created_at?->format('d/m/Y H:i') }}</p></div>
        <x-badge :tone="$customer->status === 'active' ? 'success' : 'gold'">{{ $customer->status }}</x-badge>
    </header>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Tổng quan mua hàng">
        <x-dashboard-metric label="Đơn Retail" detail="Không gồm đơn nháp"><x-formatted-number :value="$purchaseSummary['statistics']['total_orders']" /></x-dashboard-metric>
        <x-dashboard-metric label="Đơn hoàn tất"><x-formatted-number :value="$purchaseSummary['statistics']['completed_orders']" /></x-dashboard-metric>
        <x-dashboard-metric label="Đã chi ròng" detail="Thanh toán đã quyết toán trừ hoàn tiền đã hoàn tất"><x-formatted-number :value="$purchaseSummary['statistics']['total_spent']" currency /></x-dashboard-metric>
        <x-dashboard-metric label="Sản phẩm đã mua" detail="SKU sau khi trừ hàng trả hoàn tất"><x-formatted-number :value="$purchaseSummary['purchased_products_total']" /></x-dashboard-metric>
    </section>

    <section class="grid gap-4 lg:grid-cols-2">
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Thông tin khách hàng</h2><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Email</dt><dd class="break-all font-medium">{{ $customer->primary_email ?? '—' }}</dd></div><div><dt class="text-slate-500">Điện thoại</dt><dd class="font-medium">{{ $customer->primary_phone ?? '—' }}</dd></div><div><dt class="text-slate-500">Nguồn</dt><dd class="font-medium">{{ $customer->source }}</dd></div><div><dt class="text-slate-500">Cập nhật</dt><dd class="font-medium">{{ $customer->updated_at?->format('d/m/Y H:i') ?? '—' }}</dd></div></dl></div>
        <div class="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Hoạt động</h2><dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2"><div><dt class="text-slate-500">Lịch hẹn</dt><dd class="font-medium">{{ $customer->appointments_count }}</dd></div><div><dt class="text-slate-500">Lượt khám hoàn tất</dt><dd class="font-medium">{{ $customer->completed_appointments_count }}</dd></div><div><dt class="text-slate-500">Đơn đang xử lý</dt><dd class="font-medium">{{ $purchaseSummary['statistics']['processing_orders'] }}</dd></div><div><dt class="text-slate-500">Voucher khả dụng</dt><dd class="font-medium">{{ $customer->user?->available_vouchers_count ?? 0 }}</dd></div></dl></div>
    </section>

    <section aria-labelledby="customer-orders-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2"><h2 id="customer-orders-heading" class="text-xl font-semibold text-junie-navy">Lịch sử đơn Retail</h2><p class="text-sm text-slate-500">{{ $orders->total() }} đơn · Giá trị đơn được lưu tại thời điểm đặt</p></div>
        @if ($orders->isEmpty())<x-empty-state title="Khách hàng chưa có đơn Retail." />@else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[720px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Mã đơn</th><th class="px-4 py-3">Ngày tạo</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Thanh toán</th><th class="px-4 py-3 text-right">Tổng lưu</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($orders as $order)<tr><td class="px-4 py-3"><a href="{{ route('admin.web.sales.orders.show', $order) }}" class="font-semibold text-junie-navy underline">{{ $order->order_code }}</a></td><td class="px-4 py-3">{{ $order->created_at?->format('d/m/Y H:i') }}</td><td class="px-4 py-3">{{ $order->order_status }}</td><td class="px-4 py-3">{{ $order->payment_status }}</td><td class="px-4 py-3 text-right"><x-formatted-number :value="$order->grand_total" currency /></td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$orders" />
        @endif
    </section>

    <section aria-labelledby="customer-products-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2"><h2 id="customer-products-heading" class="text-xl font-semibold text-junie-navy">Sản phẩm đã mua</h2><p class="text-sm text-slate-500">{{ $products->total() }} SKU · Không gồm đơn hủy và hàng trả hoàn tất</p></div>
        @if ($products->isEmpty())<x-empty-state title="Chưa có sản phẩm đã mua." />@else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[650px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Sản phẩm / biến thể</th><th class="px-4 py-3">SKU lưu</th><th class="px-4 py-3 text-right">Số lượng</th><th class="px-4 py-3">Mua gần nhất</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($products as $product)<tr><td class="px-4 py-3"><p class="font-semibold">{{ $product->product_name }}</p><p class="text-slate-500">{{ $product->variant_name }}</p></td><td class="px-4 py-3 font-mono text-xs">{{ $product->sku }}</td><td class="px-4 py-3 text-right"><x-formatted-number :value="$product->total_quantity" /></td><td class="px-4 py-3">{{ $product->last_purchased_at }}</td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$products" />
        @endif
    </section>

    <section aria-labelledby="customer-vouchers-heading" class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2"><h2 id="customer-vouchers-heading" class="text-xl font-semibold text-junie-navy">Lịch sử voucher dịch vụ</h2><p class="text-sm text-slate-500">{{ $vouchers->total() }} voucher gắn với tài khoản</p></div>
        @if ($vouchers->isEmpty())<x-empty-state title="Chưa có voucher gắn với khách hàng." />@else
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm"><table class="w-full min-w-[620px] text-left text-sm"><thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3">Mã</th><th class="px-4 py-3">Nguồn</th><th class="px-4 py-3">Trạng thái</th><th class="px-4 py-3">Hết hạn</th><th class="px-4 py-3">Đã dùng</th></tr></thead><tbody class="divide-y divide-slate-100">@foreach ($vouchers as $voucher)<tr><td class="px-4 py-3 font-semibold">{{ $voucher->code }}</td><td class="px-4 py-3">{{ $voucher->source }}</td><td class="px-4 py-3">{{ $voucher->status }}</td><td class="px-4 py-3">{{ $voucher->expires_at?->format('d/m/Y') ?? '—' }}</td><td class="px-4 py-3">{{ $voucher->used_at?->format('d/m/Y H:i') ?? '—' }}</td></tr>@endforeach</tbody></table></div><x-pagination :paginator="$vouchers" />
        @endif
    </section>
</div>
@endsection
