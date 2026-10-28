<section class="space-y-5" aria-labelledby="erp-heading">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h2 id="erp-heading" class="text-xl font-semibold text-junie-navy">Bán hàng và vận hành</h2>
            <p class="mt-1 text-xs text-slate-500">30 ngày gần nhất · {{ $erp['period']['from'] }} – {{ $erp['period']['to'] }} · Giờ Việt Nam · Doanh thu theo thanh toán đã quyết toán</p>
        </div>
        <a href="{{ route('admin.web.reports.index') }}" class="text-sm font-medium text-junie-navy hover:underline">Xem báo cáo chi tiết →</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-dashboard-metric label="Doanh thu ròng"><x-formatted-number :value="$erp['sales']['net']" :currency="true" /></x-dashboard-metric>
        <x-dashboard-metric label="Thanh toán gộp"><x-formatted-number :value="$erp['sales']['gross']" :currency="true" /></x-dashboard-metric>
        <x-dashboard-metric label="Hoàn tiền"><x-formatted-number :value="$erp['sales']['refunds']" :currency="true" /></x-dashboard-metric>
        <x-dashboard-metric label="Đơn tạo mới" detail="Theo ngày tạo đơn"><x-formatted-number :value="$erp['orders']['created_count']" /></x-dashboard-metric>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <div class="min-w-0">
            <h3 class="mb-2 font-semibold text-junie-navy">Xu hướng doanh thu</h3>
            @if (count($erp['trend']) > 0)
                <div class="h-80 min-w-0 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                    <canvas id="revenue-chart" role="img" aria-label="Biểu đồ doanh thu gộp, hoàn tiền và ròng theo ngày"></canvas>
                </div>
            @else
                <div class="flex h-72 items-center justify-center rounded-2xl border border-slate-200 bg-white p-5 text-center text-sm text-slate-500">Chưa có giao dịch quyết toán trong khoảng đã chọn.</div>
            @endif
        </div>
        <div class="min-w-0 space-y-4">
            <h3 class="font-semibold text-junie-navy">Kênh bán hàng</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-dashboard-metric label="Bán lẻ · ròng"><x-formatted-number :value="$erp['channels']['retail']['net']" :currency="true" /></x-dashboard-metric>
                <x-dashboard-metric label="Đại lý · ròng"><x-formatted-number :value="$erp['channels']['dealer']['net']" :currency="true" /></x-dashboard-metric>
            </div>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-80 text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3 font-semibold">Trạng thái đơn</th><th class="px-4 py-3 text-right font-semibold">Số đơn</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($erp['orders']['by_status'] as $orderStatus)
                            <tr><td class="px-4 py-3">{{ $orderStatus['status'] }}</td><td class="px-4 py-3 text-right"><x-formatted-number :value="$orderStatus['count']" /></td></tr>
                        @empty
                            <tr><td colspan="2" class="px-4 py-6 text-center text-slate-500">Không có dữ liệu trong khoảng đã chọn.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <div class="min-w-0">
            <h3 class="mb-2 font-semibold text-junie-navy">SKU xuất bán nhiều nhất</h3>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[480px] text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3 font-semibold">SKU</th><th class="px-4 py-3 font-semibold">Sản phẩm</th><th class="px-4 py-3 text-right font-semibold">Số lượng xuất</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($erp['top_products'] as $product)
                            <tr><td class="px-4 py-3 font-medium text-junie-navy">{{ $product['sku'] }}</td><td class="px-4 py-3">{{ $product['product_name'] }}</td><td class="px-4 py-3 text-right"><x-formatted-number :value="$product['fulfilled_quantity']" /></td></tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Không có dữ liệu trong khoảng đã chọn.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="min-w-0">
            <h3 class="mb-2 font-semibold text-junie-navy">Kho và mua hàng</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-dashboard-metric label="Khả dụng hiện tại" detail="Snapshot, không theo kỳ"><x-formatted-number :value="$erp['inventory']['available']" /></x-dashboard-metric>
                <x-dashboard-metric label="SKU tồn thấp"><x-formatted-number :value="$erp['inventory']['low_stock_rows']" /></x-dashboard-metric>
                <x-dashboard-metric label="Phiếu mua"><x-formatted-number :value="$erp['procurement']['purchase_order_count']" /></x-dashboard-metric>
                <x-dashboard-metric label="Giá trị đã nhận" detail="Theo ngày nhận hàng"><x-formatted-number :value="$erp['procurement']['completed_receipt_value']" :currency="true" /></x-dashboard-metric>
            </div>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-2">
        <div class="min-w-0">
            <h3 class="mb-2 font-semibold text-junie-navy">Đại lý theo doanh thu ròng</h3>
            <div class="overflow-x-auto rounded-2xl border border-slate-200 bg-white shadow-sm">
                <table class="w-full min-w-[440px] text-left text-sm">
                    <thead class="bg-slate-50 text-slate-600"><tr><th class="px-4 py-3 font-semibold">Đại lý</th><th class="px-4 py-3 font-semibold">Tier hiệu lực</th><th class="px-4 py-3 text-right font-semibold">Doanh thu ròng</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($erp['top_dealers'] as $dealer)
                            <tr><td class="px-4 py-3 font-medium text-junie-navy">{{ $dealer['code'] }}</td><td class="px-4 py-3">{{ $dealer['effective_tier'] ?? '—' }}</td><td class="px-4 py-3 text-right"><x-formatted-number :value="$dealer['net']" :currency="true" /></td></tr>
                        @empty
                            <tr><td colspan="3" class="px-4 py-6 text-center text-slate-500">Không có dữ liệu trong khoảng đã chọn.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div class="min-w-0">
            <h3 class="mb-2 font-semibold text-junie-navy">Khuyến mãi và ví đại lý</h3>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-dashboard-metric label="Chiết khấu còn hiệu lực" detail="Snapshot, không trừ lần hai khỏi doanh thu"><x-formatted-number :value="$erp['promotions']['redeemed_discount_snapshot']" :currency="true" /></x-dashboard-metric>
                <x-dashboard-metric label="Số dư ví hiện tại" detail="Không phải doanh thu"><x-formatted-number :value="$erp['wallets']['current_balance']" :currency="true" /></x-dashboard-metric>
                <x-dashboard-metric label="PayOS Top-Up đã trả"><x-formatted-number :value="$erp['wallets']['paid_payos_topups']['amount']" :currency="true" /></x-dashboard-metric>
                <x-dashboard-metric label="Deposit hoàn tất"><x-formatted-number :value="$erp['wallets']['completed_deposits']['amount']" :currency="true" /></x-dashboard-metric>
            </div>
        </div>
    </div>
</section>
