<?php

namespace App\Services;

use App\Support\ReportPeriod;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

class ErpReportService
{
    private function money(mixed $amount): string
    {
        return bcadd((string) ($amount ?? '0'), '0', 2);
    }

    private function quantity(mixed $amount): string
    {
        return bcadd((string) ($amount ?? '0'), '0', 3);
    }

    private function salesPayments(ReportPeriod $period, ?string $channel = null): Builder
    {
        $query = DB::table('payment_allocations as a')
            ->join('payments as p', 'p.id', '=', 'a.payment_id')
            ->join('sales_orders as o', 'o.id', '=', 'a.sales_order_id')
            ->where('p.status', 'settled')
            ->whereIn('o.sales_channel', ['retail', 'dealer'])
            ->whereColumn('p.payment_context', 'o.sales_channel');
        $period->on($query, 'p.settled_at');
        $this->salesScope($query, $period, $channel);

        return $query;
    }

    private function salesRefunds(ReportPeriod $period, ?string $channel = null): Builder
    {
        $query = DB::table('refund_allocations as a')
            ->join('refunds as r', 'r.id', '=', 'a.refund_id')
            ->join('payment_allocations as pa', 'pa.id', '=', 'a.payment_allocation_id')
            ->join('payments as p', 'p.id', '=', 'pa.payment_id')
            ->join('sales_orders as o', 'o.id', '=', 'pa.sales_order_id')
            ->whereColumn('r.sales_order_id', 'o.id')
            ->where('r.status', 'completed')
            ->where('p.status', 'settled')
            ->whereColumn('p.payment_context', 'o.sales_channel')
            ->whereIn('o.sales_channel', ['retail', 'dealer']);
        $period->on($query, 'r.completed_at');
        $this->salesScope($query, $period, $channel);

        return $query;
    }

    private function salesScope(Builder $query, ReportPeriod $period, ?string $channel): void
    {
        $selectedChannel = $channel ?? $period->channel;
        if ($selectedChannel !== 'all') {
            $query->where('o.sales_channel', $selectedChannel);
        }
        if ($period->dealerId !== null) {
            $query->where('o.dealer_account_id', $period->dealerId);
        }
        if ($period->warehouseId !== null) {
            $query->where('o.warehouse_id', $period->warehouseId);
        }
    }

    /** @return array{gross: string, refunds: string, net: string, currency: string} */
    public function salesTotals(ReportPeriod $period, ?string $channel = null): array
    {
        $payments = $this->salesPayments($period, $channel);
        $refunds = $this->salesRefunds($period, $channel);
        if ((clone $payments)->where(fn (Builder $query) => $query->where('p.currency', '!=', 'VND')->orWhere('o.currency', '!=', 'VND'))->exists()
            || (clone $refunds)->where(fn (Builder $query) => $query->where('r.currency', '!=', 'VND')->orWhere('p.currency', '!=', 'VND')->orWhere('o.currency', '!=', 'VND'))->exists()) {
            throw new HttpResponseException(response()->json(['code' => 'REPORT_LEDGER_CURRENCY_MISMATCH'], 409));
        }

        $gross = $this->money((clone $payments)->sum('a.allocated_amount'));
        $returned = $this->money((clone $refunds)->sum('a.amount'));

        return ['gross' => $gross, 'refunds' => $returned, 'net' => bcsub($gross, $returned, 2), 'currency' => 'VND'];
    }

    /** @return array<string, mixed> */
    public function overview(ReportPeriod $period): array
    {
        $orders = $this->orders($period);

        return [
            'period' => $period->metadata(),
            'sales' => $this->salesTotals($period),
            'channels' => [
                'retail' => $this->salesTotals($period, 'retail'),
                'dealer' => $this->salesTotals($period, 'dealer'),
            ],
            'orders' => $orders['summary'],
            'trend' => $this->salesTrend($period),
            'top_products' => $this->products($period)['top_skus'],
            'top_dealers' => $this->dealers($period)['top_dealers'],
            'inventory' => $this->inventory($period)['summary'],
            'procurement' => $this->procurement($period)['summary'],
            'promotions' => $this->promotions($period),
            'wallets' => $this->wallets($period),
        ];
    }

    /** @return array<string, mixed> */
    public function sales(ReportPeriod $period): array
    {
        return [
            'period' => $period->metadata(),
            'totals' => $this->salesTotals($period),
            'channels' => [
                'retail' => $this->salesTotals($period, 'retail'),
                'dealer' => $this->salesTotals($period, 'dealer'),
            ],
            'trend' => $this->salesTrend($period),
        ];
    }

    /** @return list<array{date: string, gross: string, refunds: string, net: string}> */
    private function salesTrend(ReportPeriod $period): array
    {
        $grossRows = $this->salesPayments($period)
            ->selectRaw('DATE(p.settled_at) as report_date, SUM(a.allocated_amount) as amount')
            ->groupByRaw('DATE(p.settled_at)')->get();
        $refundRows = $this->salesRefunds($period)
            ->selectRaw('DATE(r.completed_at) as report_date, SUM(a.amount) as amount')
            ->groupByRaw('DATE(r.completed_at)')->get();
        $points = [];
        foreach ($grossRows as $row) {
            $points[$row->report_date] = ['date' => $row->report_date, 'gross' => $this->money($row->amount), 'refunds' => '0.00'];
        }
        foreach ($refundRows as $row) {
            $points[$row->report_date] ??= ['date' => $row->report_date, 'gross' => '0.00', 'refunds' => '0.00'];
            $points[$row->report_date]['refunds'] = $this->money($row->amount);
        }
        ksort($points);

        return array_values(array_map(static fn (array $point): array => $point + [
            'net' => bcsub($point['gross'], $point['refunds'], 2),
        ], $points));
    }

    /** @return array<string, mixed> */
    public function orders(ReportPeriod $period): array
    {
        $query = DB::table('sales_orders as o');
        $period->on($query, 'o.created_at');
        $this->salesScope($query, $period, null);
        $statusRows = (clone $query)->select('o.order_status')->selectRaw('COUNT(*) as orders_count')
            ->groupBy('o.order_status')->orderBy('o.order_status')->get();
        $channelRows = (clone $query)->select('o.sales_channel')->selectRaw('COUNT(*) as orders_count')
            ->groupBy('o.sales_channel')->orderBy('o.sales_channel')->get();

        return [
            'period' => $period->metadata(),
            'summary' => [
                'created_count' => (clone $query)->count(),
                'by_status' => $statusRows->map(fn (object $row): array => ['status' => $row->order_status, 'count' => (int) $row->orders_count])->all(),
                'by_channel' => $channelRows->map(fn (object $row): array => ['channel' => $row->sales_channel, 'count' => (int) $row->orders_count])->all(),
            ],
            'recent' => (clone $query)->select(['o.id', 'o.order_code', 'o.sales_channel', 'o.order_status', 'o.payment_status', 'o.fulfillment_status', 'o.grand_total', 'o.created_at'])
                ->orderByDesc('o.created_at')->orderByDesc('o.id')->limit(20)->get(),
        ];
    }

    /** @return array<string, mixed> */
    public function products(ReportPeriod $period): array
    {
        $shipments = DB::table('stock_movements as m')
            ->join('sales_order_items as i', 'i.id', '=', 'm.reference_id')
            ->join('sales_orders as o', 'o.id', '=', 'i.sales_order_id')
            ->join('product_variants as v', 'v.id', '=', 'm.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id')
            ->where('m.movement_type', 'SALES_ORDER_SHIPMENT')
            ->where('m.reference_type', 'SALES_ORDER_ITEM');
        $period->on($shipments, 'm.occurred_at');
        $this->salesScope($shipments, $period, null);
        $top = $shipments->select(['v.id', 'v.sku', 'p.name as product_name'])
            ->selectRaw('-SUM(m.quantity) as fulfilled_quantity')
            ->groupBy('v.id', 'v.sku', 'p.name')
            ->orderByDesc('fulfilled_quantity')->orderBy('v.id')->limit(10)->get()
            ->map(fn (object $row): array => ['sku_id' => (int) $row->id, 'sku' => $row->sku, 'product_name' => $row->product_name, 'fulfilled_quantity' => $this->quantity($row->fulfilled_quantity)])->all();
        $returns = DB::table('sales_return_items as i')
            ->join('sales_returns as r', 'r.id', '=', 'i.sales_return_id')
            ->join('sales_order_items as oi', 'oi.id', '=', 'i.sales_order_item_id')
            ->join('sales_orders as o', 'o.id', '=', 'oi.sales_order_id')
            ->where('r.status', 'completed');
        $period->on($returns, 'r.completed_at');
        $this->salesScope($returns, $period, null);

        return [
            'period' => $period->metadata(),
            'ranking_metric' => 'fulfilled_shipment_quantity',
            'top_skus' => $top,
            'completed_return_quantity' => $this->quantity($returns->sum('i.quantity')),
        ];
    }

    /** @return array<string, mixed> */
    public function inventory(ReportPeriod $period): array
    {
        $balances = DB::table('inventory_balances as b')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')
            ->join('product_variants as v', 'v.id', '=', 'b.product_variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id');
        if ($period->warehouseId !== null) {
            $balances->where('b.warehouse_id', $period->warehouseId);
        }
        $totals = (clone $balances)->selectRaw('COUNT(*) as balance_rows, COUNT(DISTINCT b.warehouse_id) as warehouse_count, COUNT(DISTINCT b.product_variant_id) as sku_count')
            ->selectRaw('COALESCE(SUM(b.on_hand_quantity), 0) as on_hand, COALESCE(SUM(b.reserved_quantity), 0) as reserved')
            ->selectRaw('COALESCE(SUM(b.on_hand_quantity - b.reserved_quantity), 0) as available')
            ->selectRaw('SUM(CASE WHEN b.on_hand_quantity - b.reserved_quantity <= 0 THEN 1 ELSE 0 END) as out_of_stock')
            ->selectRaw('SUM(CASE WHEN p.track_inventory = 1 AND v.track_inventory = 1 AND p.default_low_stock_threshold IS NOT NULL AND b.on_hand_quantity - b.reserved_quantity > 0 AND b.on_hand_quantity - b.reserved_quantity <= p.default_low_stock_threshold THEN 1 ELSE 0 END) as low_stock')
            ->first();
        $warehouses = (clone $balances)->select(['w.id', 'w.code', 'w.name'])
            ->selectRaw('SUM(b.on_hand_quantity) as on_hand, SUM(b.reserved_quantity) as reserved, SUM(b.on_hand_quantity - b.reserved_quantity) as available')
            ->groupBy('w.id', 'w.code', 'w.name')->orderBy('w.code')->get()
            ->map(fn (object $row): array => [
                'warehouse_id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name,
                'on_hand' => $this->quantity($row->on_hand), 'reserved' => $this->quantity($row->reserved), 'available' => $this->quantity($row->available),
            ])->all();
        $movements = DB::table('stock_movements as m');
        $period->on($movements, 'm.occurred_at');
        if ($period->warehouseId !== null) {
            $movements->where('m.warehouse_id', $period->warehouseId);
        }
        $movementRows = $movements->select('m.movement_type')->selectRaw('COUNT(*) as movement_count, SUM(m.quantity) as signed_quantity')
            ->groupBy('m.movement_type')->orderBy('m.movement_type')->get()
            ->map(fn (object $row): array => ['type' => $row->movement_type, 'count' => (int) $row->movement_count, 'signed_quantity' => $this->quantity($row->signed_quantity)])->all();

        return [
            'period' => $period->metadata(),
            'snapshot_at' => now('Asia/Ho_Chi_Minh')->toIso8601String(),
            'summary' => [
                'balance_rows' => (int) ($totals->balance_rows ?? 0), 'warehouse_count' => (int) ($totals->warehouse_count ?? 0),
                'sku_count' => (int) ($totals->sku_count ?? 0), 'on_hand' => $this->quantity($totals->on_hand ?? 0),
                'reserved' => $this->quantity($totals->reserved ?? 0), 'available' => $this->quantity($totals->available ?? 0),
                'out_of_stock_rows' => (int) ($totals->out_of_stock ?? 0), 'low_stock_rows' => (int) ($totals->low_stock ?? 0),
            ],
            'warehouses' => $warehouses,
            'movements' => $movementRows,
        ];
    }

    private function procurementOrders(ReportPeriod $period): Builder
    {
        $query = DB::table('purchase_orders as o');
        $period->on($query, 'o.created_at');
        $this->procurementScope($query, $period);

        return $query;
    }

    private function procurementReceipts(ReportPeriod $period): Builder
    {
        $query = DB::table('goods_receipt_items as i')
            ->join('goods_receipts as d', 'd.id', '=', 'i.goods_receipt_id')
            ->join('purchase_order_items as pi', 'pi.id', '=', 'i.purchase_order_item_id')
            ->join('purchase_orders as o', 'o.id', '=', 'd.purchase_order_id');
        $period->on($query, 'd.received_at');
        $this->procurementScope($query, $period);

        return $query;
    }

    private function procurementReturns(ReportPeriod $period): Builder
    {
        $query = DB::table('purchase_return_items as i')
            ->join('purchase_returns as d', 'd.id', '=', 'i.purchase_return_id')
            ->join('goods_receipt_items as ri', 'ri.id', '=', 'i.goods_receipt_item_id')
            ->join('purchase_order_items as pi', 'pi.id', '=', 'ri.purchase_order_item_id')
            ->join('purchase_orders as o', 'o.id', '=', 'd.purchase_order_id');
        $period->on($query, 'd.returned_at');
        $this->procurementScope($query, $period);

        return $query;
    }

    private function procurementScope(Builder $query, ReportPeriod $period): void
    {
        if ($period->supplierId !== null) {
            $query->where('o.supplier_id', $period->supplierId);
        }
        if ($period->warehouseId !== null) {
            $query->where('o.warehouse_id', $period->warehouseId);
        }
    }

    /** @return array<string, mixed> */
    public function procurement(ReportPeriod $period): array
    {
        $orders = $this->procurementOrders($period);
        $receipts = $this->procurementReceipts($period);
        $returns = $this->procurementReturns($period);
        $ordered = $this->money((clone $orders)->where('o.status', '!=', 'cancelled')->sum('o.total_amount'));
        $received = $this->money((clone $receipts)->selectRaw('SUM(i.quantity * pi.unit_price) as amount')->first()?->amount);
        $returned = $this->money((clone $returns)->selectRaw('SUM(i.quantity * pi.unit_price) as amount')->first()?->amount);
        $status = (clone $orders)->select('o.status')->selectRaw('COUNT(*) as orders_count')
            ->groupBy('o.status')->orderBy('o.status')->get()
            ->map(fn (object $row): array => ['status' => $row->status, 'count' => (int) $row->orders_count])->all();
        $supplierFacts = (clone $receipts)->select('o.supplier_id')->selectRaw('SUM(i.quantity * pi.unit_price) as received, 0 as returned')
            ->groupBy('o.supplier_id')
            ->unionAll((clone $returns)->select('o.supplier_id')->selectRaw('0 as received, SUM(i.quantity * pi.unit_price) as returned')->groupBy('o.supplier_id'));
        $suppliers = DB::query()->fromSub($supplierFacts, 'facts')
            ->join('suppliers as s', 's.id', '=', 'facts.supplier_id')
            ->select(['s.id', 's.code', 's.name'])
            ->selectRaw('SUM(facts.received) as received, SUM(facts.returned) as returned, SUM(facts.received) - SUM(facts.returned) as net_received')
            ->groupBy('s.id', 's.code', 's.name')->orderByDesc('net_received')->orderBy('s.id')->limit(10)->get()
            ->map(fn (object $row): array => [
                'supplier_id' => (int) $row->id, 'code' => $row->code, 'name' => $row->name,
                'received_value' => $this->money($row->received), 'return_value' => $this->money($row->returned),
                'net_received_value' => $this->money($row->net_received),
            ])->all();

        return [
            'period' => $period->metadata(),
            'summary' => [
                'purchase_order_count' => (clone $orders)->count(),
                'non_cancelled_ordered_value' => $ordered,
                'completed_receipt_value' => $received,
                'purchase_return_value' => $returned,
                'net_received_value' => bcsub($received, $returned, 2),
                'currency' => 'VND',
                'by_status' => $status,
            ],
            'top_suppliers' => $suppliers,
        ];
    }

    /** @return array<string, mixed> */
    public function dealers(ReportPeriod $period): array
    {
        $settled = $this->salesPayments($period, 'dealer')->select('o.dealer_account_id')
            ->selectRaw('SUM(a.allocated_amount) as gross, 0 as refunds')->groupBy('o.dealer_account_id');
        $refunded = $this->salesRefunds($period, 'dealer')->select('o.dealer_account_id')
            ->selectRaw('0 as gross, SUM(a.amount) as refunds')->groupBy('o.dealer_account_id');
        $facts = $settled->unionAll($refunded);
        $ranked = DB::query()->fromSub($facts, 'facts')
            ->join('dealer_accounts as d', 'd.id', '=', 'facts.dealer_account_id')
            ->leftJoin('dealer_tiers as base', 'base.id', '=', 'd.current_tier_id')
            ->select(['d.id', 'd.code', 'd.legal_name', 'base.name as base_tier'])
            ->selectRaw('SUM(facts.gross) as gross, SUM(facts.refunds) as refunds, SUM(facts.gross) - SUM(facts.refunds) as net')
            ->groupBy('d.id', 'd.code', 'd.legal_name', 'base.name')
            ->orderByDesc('net')->orderBy('d.id')->limit(10)->get();
        $ids = $ranked->pluck('id')->all();
        $overrides = DB::table('dealer_tier_overrides as o')->join('dealer_tiers as t', 't.id', '=', 'o.tier_id')
            ->whereIn('o.dealer_account_id', $ids)->where('o.status', 'active')
            ->where('o.starts_at', '<=', now())->where(fn (Builder $query) => $query->whereNull('o.ends_at')->orWhere('o.ends_at', '>', now()))
            ->orderByDesc('o.starts_at')->orderByDesc('o.id')
            ->select(['o.dealer_account_id', 't.name'])->get()->unique('dealer_account_id')->keyBy('dealer_account_id');
        $distribution = DB::table('dealer_accounts as d')->leftJoin('dealer_tiers as t', 't.id', '=', 'd.current_tier_id')
            ->selectRaw("COALESCE(t.name, 'Unassigned') as tier_name, COUNT(*) as dealer_count")
            ->groupBy('t.name')->orderBy('tier_name')->get()
            ->map(fn (object $row): array => ['base_tier' => $row->tier_name, 'dealer_count' => (int) $row->dealer_count])->all();

        return [
            'period' => $period->metadata(),
            'totals' => $this->salesTotals($period, 'dealer'),
            'top_dealers' => $ranked->map(fn (object $row): array => [
                'dealer_id' => (int) $row->id, 'code' => $row->code, 'name' => $row->legal_name,
                'gross' => $this->money($row->gross), 'refunds' => $this->money($row->refunds), 'net' => $this->money($row->net),
                'base_tier' => $row->base_tier, 'effective_tier' => $overrides->get($row->id)?->name ?? $row->base_tier,
                'has_active_override' => $overrides->has($row->id),
            ])->all(),
            'base_tier_distribution' => $distribution,
        ];
    }

    /** @return array<string, mixed> */
    public function wallets(ReportPeriod $period): array
    {
        $wallets = DB::table('dealer_wallets as w');
        if ($period->dealerId !== null) {
            $wallets->where('w.dealer_account_id', $period->dealerId);
        }
        $flows = DB::table('dealer_wallet_transactions as t')->join('dealer_wallets as w', 'w.id', '=', 't.dealer_wallet_id');
        $period->on($flows, 't.created_at');
        if ($period->dealerId !== null) {
            $flows->where('w.dealer_account_id', $period->dealerId);
        }
        $flowRows = $flows->select('t.type')->selectRaw('COUNT(*) as transaction_count, SUM(t.amount) as amount')
            ->groupBy('t.type')->orderBy('t.type')->get()
            ->map(fn (object $row): array => ['type' => $row->type, 'count' => (int) $row->transaction_count, 'amount' => $this->money($row->amount)])->all();
        $deposits = DB::table('dealer_wallet_deposits as d')->where('d.status', 'completed');
        $period->on($deposits, 'd.completed_at');
        $topUps = DB::table('dealer_wallet_top_up_requests as t')->where('t.status', 'paid');
        $period->on($topUps, 't.paid_at');
        if ($period->dealerId !== null) {
            $deposits->where('d.dealer_account_id', $period->dealerId);
            $topUps->where('t.dealer_account_id', $period->dealerId);
        }

        return [
            'period' => $period->metadata(),
            'current_balance' => $this->money((clone $wallets)->sum('w.balance')),
            'wallet_count' => (clone $wallets)->count(),
            'completed_deposits' => ['count' => (clone $deposits)->count(), 'amount' => $this->money((clone $deposits)->sum('d.amount'))],
            'paid_payos_topups' => ['count' => (clone $topUps)->count(), 'amount' => $this->money((clone $topUps)->sum('t.amount'))],
            'flows' => $flowRows,
        ];
    }

    /** @return array<string, mixed> */
    public function promotions(ReportPeriod $period): array
    {
        $redemptions = DB::table('sales_promotion_redemptions as r');
        $period->on($redemptions, 'r.redeemed_at');
        if ($period->channel !== 'all') {
            $redemptions->where('r.sales_channel', $period->channel);
        }
        if ($period->dealerId !== null) {
            $redemptions->where('r.dealer_account_id', $period->dealerId);
        }
        $rows = (clone $redemptions)->select('r.status')->selectRaw('COUNT(*) as uses_count, SUM(r.discount_amount) as discount')
            ->groupBy('r.status')->orderBy('r.status')->get()
            ->map(fn (object $row): array => ['status' => $row->status, 'count' => (int) $row->uses_count, 'discount_snapshot' => $this->money($row->discount)])->all();
        $released = DB::table('sales_promotion_redemptions as r')->where('r.status', 'released');
        $period->on($released, 'r.released_at');
        if ($period->channel !== 'all') {
            $released->where('r.sales_channel', $period->channel);
        }
        if ($period->dealerId !== null) {
            $released->where('r.dealer_account_id', $period->dealerId);
        }

        return [
            'period' => $period->metadata(),
            'redemptions_by_current_status' => $rows,
            'redeemed_discount_snapshot' => $this->money((clone $redemptions)->where('r.status', 'redeemed')->sum('r.discount_amount')),
            'released_in_period' => ['count' => (clone $released)->count(), 'discount_snapshot' => $this->money((clone $released)->sum('r.discount_amount'))],
        ];
    }

    /** @return array<string, mixed> */
    public function clinic(ReportPeriod $period): array
    {
        $query = DB::table('appointments as a')
            ->where('a.appointment_date', '>=', $period->start->toDateString())
            ->where('a.appointment_date', '<', $period->endExclusive->toDateString());
        $status = (clone $query)->select('a.status')->selectRaw('COUNT(*) as appointments_count')
            ->groupBy('a.status')->orderBy('a.status')->get()
            ->map(fn (object $row): array => ['status' => $row->status, 'count' => (int) $row->appointments_count])->all();

        return ['period' => $period->metadata(), 'appointment_count' => (clone $query)->count(), 'by_status' => $status];
    }
}
