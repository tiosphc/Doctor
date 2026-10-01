<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\SalesOrder;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class RetailCustomerPurchaseService
{
    /**
     * @return array<string, mixed>
     */
    public function summary(Customer $customer, OrderPaymentSummaryService $payments): array
    {
        if ($customer->user_id === null) {
            return [
                'statistics' => ['total_orders' => 0, 'completed_orders' => 0, 'processing_orders' => 0, 'cancelled_orders' => 0, 'total_spent' => '0.00', 'average_order_value' => '0.00'],
                'last_order_at' => null,
                'recent_orders' => [],
                'purchased_products' => [],
                'purchased_products_total' => 0,
            ];
        }

        $orders = $this->orders($customer);
        $counts = (clone $orders)->selectRaw("COUNT(*) AS total_orders, SUM(CASE WHEN order_status = 'completed' THEN 1 ELSE 0 END) AS completed_orders, SUM(CASE WHEN order_status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled_orders, SUM(CASE WHEN order_status IN ('pending', 'confirmed', 'preparing', 'processing', 'shipping', 'delivered') THEN 1 ELSE 0 END) AS processing_orders, MAX(created_at) AS last_order_at")->first();

        $paidOrders = (clone $orders)->where('order_status', '!=', 'cancelled')->whereHas('paymentAllocations.payment', fn ($query) => $query->where('status', 'settled')->where('payment_context', 'retail'));
        $paidOrderCount = (clone $paidOrders)->count();
        $paid = DB::table('payment_allocations')->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.status', 'settled')->where('payments.payment_context', 'retail')
            ->whereIn('payment_allocations.sales_order_id', (clone $orders)->where('order_status', '!=', 'cancelled')->select('id'))
            ->sum('payment_allocations.allocated_amount');
        $refunded = DB::table('refund_allocations')->join('refunds', 'refunds.id', '=', 'refund_allocations.refund_id')
            ->join('payment_allocations', 'payment_allocations.id', '=', 'refund_allocations.payment_allocation_id')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('refunds.status', 'completed')->where('payments.status', 'settled')->where('payments.payment_context', 'retail')
            ->whereIn('payment_allocations.sales_order_id', (clone $orders)->where('order_status', '!=', 'cancelled')->select('id'))
            ->sum('refund_allocations.amount');
        $netSpent = bccomp((string) $paid, (string) $refunded, 2) > 0 ? bcsub((string) $paid, (string) $refunded, 2) : '0.00';

        $recent = $payments->withSettledSum((clone $orders)->withSum('items as item_quantity', 'quantity'))
            ->orderByDesc('created_at')->orderByDesc('id')->limit(8)->get()
            ->map(function (SalesOrder $order) use ($payments): array {
                $payment = $payments->summary($order);

                return [
                    'id' => $order->id, 'order_code' => $order->order_code, 'created_at' => $order->created_at,
                    'item_quantity' => (string) ($order->item_quantity ?? '0'), 'grand_total' => $order->grand_total,
                    'order_status' => $order->order_status, 'payment_status' => $payment['payment_status'],
                    'refund_status' => $payment['refund_status'],
                ];
            })->all();

        $products = $this->productsQuery($customer);
        $productCount = DB::query()->fromSub(clone $products, 'purchased_products')->count();

        return [
            'statistics' => [
                'total_orders' => (int) ($counts?->total_orders ?? 0),
                'completed_orders' => (int) ($counts?->completed_orders ?? 0),
                'processing_orders' => (int) ($counts?->processing_orders ?? 0),
                'cancelled_orders' => (int) ($counts?->cancelled_orders ?? 0),
                'total_spent' => $netSpent,
                'average_order_value' => $paidOrderCount > 0 ? bcdiv($netSpent, (string) $paidOrderCount, 2) : '0.00',
            ],
            'last_order_at' => $counts?->last_order_at,
            'recent_orders' => $recent,
            'purchased_products' => (clone $products)->orderByDesc('last_purchased_at')->orderBy('sku')->orderBy('product_variant_id')->limit(8)->get(),
            'purchased_products_total' => $productCount,
        ];
    }

    public function products(Customer $customer, int $page): LengthAwarePaginator
    {
        if ($customer->user_id === null) {
            return DB::table('sales_order_items')->whereRaw('1 = 0')->paginate(20, ['*'], 'page', $page);
        }

        return $this->productsQuery($customer)->orderByDesc('last_purchased_at')->orderBy('sku')->orderBy('product_variant_id')->paginate(20, ['*'], 'page', $page);
    }

    private function orders(Customer $customer): EloquentBuilder
    {
        return SalesOrder::query()->where('sales_channel', 'retail')->where('buyer_user_id', $customer->user_id)->where('order_status', '!=', 'draft');
    }

    private function productsQuery(Customer $customer): Builder
    {
        $customerItems = DB::table('sales_order_items AS scoped_items')
            ->join('sales_orders AS scoped_orders', 'scoped_orders.id', '=', 'scoped_items.sales_order_id')
            ->where('scoped_orders.sales_channel', 'retail')->where('scoped_orders.buyer_user_id', $customer->user_id)
            ->select('scoped_items.id');
        $returned = DB::table('sales_return_items')->join('sales_returns', 'sales_returns.id', '=', 'sales_return_items.sales_return_id')
            ->whereIn('sales_return_items.sales_order_item_id', $customerItems)
            ->where('sales_returns.status', 'completed')->groupBy('sales_return_items.sales_order_item_id')
            ->selectRaw('sales_return_items.sales_order_item_id, SUM(sales_return_items.quantity) AS returned_quantity');

        return DB::table('sales_order_items AS items')->join('sales_orders AS orders', 'orders.id', '=', 'items.sales_order_id')
            ->leftJoinSub($returned, 'returned', 'returned.sales_order_item_id', '=', 'items.id')
            ->where('orders.sales_channel', 'retail')->where('orders.buyer_user_id', $customer->user_id)
            ->whereNotIn('orders.order_status', ['draft', 'cancelled'])->where('items.is_gift', false)
            ->groupBy('items.product_variant_id', 'items.sku_snapshot')
            ->selectRaw('MAX(items.product_id) AS product_id, items.product_variant_id, items.sku_snapshot AS sku, MAX(items.product_name_snapshot) AS product_name, MAX(items.variant_name_snapshot) AS variant_name, SUM(CASE WHEN items.quantity > COALESCE(returned.returned_quantity, 0) THEN items.quantity - COALESCE(returned.returned_quantity, 0) ELSE 0 END) AS total_quantity, MAX(orders.created_at) AS last_purchased_at')
            ->havingRaw('SUM(CASE WHEN items.quantity > COALESCE(returned.returned_quantity, 0) THEN items.quantity - COALESCE(returned.returned_quantity, 0) ELSE 0 END) > 0');
    }
}
