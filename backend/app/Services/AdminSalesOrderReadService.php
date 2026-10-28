<?php

namespace App\Services;

use App\Models\SalesOrder;
use Illuminate\Pagination\LengthAwarePaginator;

class AdminSalesOrderReadService
{
    public function __construct(private readonly OrderPaymentSummaryService $summaries) {}

    /** @param array<string, mixed> $filters */
    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = $this->summaries->withSettledSum(SalesOrder::query()
            ->with(['buyer:id,name,email', 'warehouse:id,code,name'])
            ->withCount(['items as items_count' => fn ($builder) => $builder->where('is_gift', false)])
            ->withSum(['items as total_quantity' => fn ($builder) => $builder->where('is_gift', false)], 'quantity'));

        foreach (['buyer_user_id', 'warehouse_id', 'dealer_account_id', 'sales_channel', 'order_source', 'order_status', 'payment_status', 'payment_method', 'fulfillment_status'] as $field) {
            if (isset($filters[$field])) {
                $query->where($field, $filters[$field]);
            }
        }
        if (isset($filters['search'])) {
            $query->where(fn ($builder) => $builder->where('order_code', 'like', '%'.$filters['search'].'%')
                ->orWhere('recipient_name', 'like', '%'.$filters['search'].'%')
                ->orWhere('recipient_phone', 'like', '%'.$filters['search'].'%')
                ->orWhere('external_reference', 'like', '%'.$filters['search'].'%')
                ->orWhere('dealer_code_snapshot', 'like', '%'.$filters['search'].'%')
                ->orWhere('dealer_name_snapshot', 'like', '%'.$filters['search'].'%'));
        }
        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query->latest('id')->paginate($filters['per_page'] ?? 20)
            ->through(fn (SalesOrder $order): SalesOrder => $this->summaries->attach($order));
    }
}
