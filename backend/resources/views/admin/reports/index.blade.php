@extends('layouts.admin')

@section('title', 'Báo cáo ERP')
@section('page-title', 'Báo cáo ERP')

@section('content')
<div class="space-y-6">
    <header><p class="text-xs font-semibold uppercase tracking-wide text-junie-gold">Tổng quan</p><h1 class="mt-2 text-3xl font-semibold text-junie-navy">Báo cáo ERP</h1><p class="mt-2 text-sm text-slate-600">{{ $data['period']['from'] }} – {{ $data['period']['to'] }} · Giờ Việt Nam. Doanh thu là phân bổ thanh toán đã quyết toán trừ phân bổ hoàn tiền đã hoàn tất.</p></header>
    @include('admin.reports.filters', ['report' => null])
    @include('admin.reports.navigation', ['report' => null])

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Chỉ số ERP">
        <x-dashboard-metric label="Doanh thu ròng"><x-formatted-number :value="$data['sales']['net']" currency /></x-dashboard-metric>
        <x-dashboard-metric label="Thanh toán gộp"><x-formatted-number :value="$data['sales']['gross']" currency /></x-dashboard-metric>
        <x-dashboard-metric label="Hoàn tiền hoàn tất"><x-formatted-number :value="$data['sales']['refunds']" currency /></x-dashboard-metric>
        <x-dashboard-metric label="Đơn tạo trong kỳ"><x-formatted-number :value="$data['orders']['created_count']" /></x-dashboard-metric>
    </section>

    <section class="grid gap-5 xl:grid-cols-2">
        <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Xu hướng doanh thu</h2>@if (count($data['trend']) > 0)<div class="mt-4 h-72"><canvas id="report-revenue-chart" role="img" aria-label="Xu hướng thanh toán gộp, hoàn tiền và doanh thu ròng theo ngày"></canvas></div>@else<p class="mt-5 text-sm text-slate-500">Chưa có thanh toán quyết toán hoặc hoàn tiền trong kỳ.</p>@endif</div>
        <div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Kênh bán</h2><div class="mt-4 grid gap-3 sm:grid-cols-2"><x-dashboard-metric label="Retail · ròng"><x-formatted-number :value="$data['channels']['retail']['net']" currency /></x-dashboard-metric><x-dashboard-metric label="Dealer · ròng"><x-formatted-number :value="$data['channels']['dealer']['net']" currency /></x-dashboard-metric></div><p class="mt-4 text-xs text-slate-500">Ngày ghi nhận doanh thu dựa trên thanh toán; ngày hoàn tiền dựa trên khoản hoàn tất.</p></div>
    </section>

    <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" aria-label="Vận hành">
        <x-dashboard-metric label="Tồn khả dụng" detail="Snapshot hiện tại, không theo kỳ"><x-formatted-number :value="$data['inventory']['available']" /></x-dashboard-metric>
        <x-dashboard-metric label="Phiếu mua" detail="Theo ngày tạo"><x-formatted-number :value="$data['procurement']['purchase_order_count']" /></x-dashboard-metric>
        <x-dashboard-metric label="Giá trị đã nhận" detail="Theo ngày nhận"><x-formatted-number :value="$data['procurement']['completed_receipt_value']" currency /></x-dashboard-metric>
        <x-dashboard-metric label="Số dư ví đại lý" detail="Snapshot hiện tại; không phải doanh thu"><x-formatted-number :value="$data['wallets']['current_balance']" currency /></x-dashboard-metric>
    </section>

    <section class="grid gap-5 lg:grid-cols-2"><div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Top 10 SKU theo số lượng xuất</h2><div class="mt-3 space-y-2 text-sm">@forelse ($data['top_products'] as $product)<div class="flex justify-between gap-3 border-b border-slate-100 py-2"><span class="min-w-0 truncate">{{ $product['sku'] }} · {{ $product['product_name'] }}</span><strong><x-formatted-number :value="$product['fulfilled_quantity']" /></strong></div>@empty<p class="text-slate-500">Chưa có dữ liệu.</p>@endforelse</div></div><div class="min-w-0 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm"><h2 class="text-lg font-semibold text-junie-navy">Top 10 đại lý theo doanh thu ròng</h2><div class="mt-3 space-y-2 text-sm">@forelse ($data['top_dealers'] as $dealer)<div class="flex justify-between gap-3 border-b border-slate-100 py-2"><span class="min-w-0 truncate">{{ $dealer['code'] }} · {{ $dealer['name'] }}</span><strong><x-formatted-number :value="$dealer['net']" currency /></strong></div>@empty<p class="text-slate-500">Chưa có dữ liệu.</p>@endforelse</div></div></section>
</div>
<script>window.adminReportTrend = {{ \Illuminate\Support\Js::from($data['trend']) }};</script>
@vite('resources/js/admin-reports.js')
@endsection
