<?php

namespace App\Services;

use App\Models\SalesOrder;
use App\Models\SalesVoucher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class SalesVoucherService
{
    public function __construct(private readonly PromotionDiscountAllocator $allocator) {}

    public function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }

    /** @return array<string, mixed> */
    public function quote(string $code, string $channel, int $buyerId, string $amount, bool $lock = false): array
    {
        if ($channel !== 'retail') {
            $this->conflict('VOUCHER_NOT_AVAILABLE_FOR_DEALER');
        }
        $query = SalesVoucher::query()->where('normalized_code', $this->normalize($code));
        $voucher = ($lock ? $query->lockForUpdate() : $query)->first();
        if ($voucher === null) {
            $this->conflict('VOUCHER_NOT_FOUND');
        }
        if ($voucher->status !== 'active') {
            $this->conflict('VOUCHER_INACTIVE');
        }
        if ($voucher->starts_at !== null && $voucher->starts_at->isFuture()) {
            $this->conflict('VOUCHER_NOT_STARTED');
        }
        if ($voucher->ends_at !== null && $voucher->ends_at->isPast()) {
            $this->conflict('VOUCHER_EXPIRED');
        }
        if ($voucher->sales_scope !== 'retail') {
            $this->conflict('VOUCHER_NOT_AVAILABLE_FOR_DEALER');
        }
        if (bccomp($amount, $voucher->minimum_order_amount, 2) < 0) {
            $this->conflict('VOUCHER_MINIMUM_NOT_MET', ['minimum_order_amount' => $voucher->minimum_order_amount]);
        }
        if ($voucher->total_usage_limit !== null && $voucher->redemptions()->where('status', 'redeemed')->count() >= $voucher->total_usage_limit) {
            $this->conflict('VOUCHER_USAGE_LIMIT_REACHED');
        }
        if ($voucher->per_buyer_usage_limit !== null && $voucher->redemptions()->where('status', 'redeemed')
            ->where('buyer_user_id', $buyerId)->count() >= $voucher->per_buyer_usage_limit) {
            $this->conflict('VOUCHER_BUYER_LIMIT_REACHED');
        }
        $discount = $voucher->discount_type === 'percentage'
            ? bcadd(bcdiv(bcmul($amount, $voucher->discount_value, 4), '100', 4), '0.005', 2)
            : $voucher->discount_value;
        if ($voucher->max_discount_amount !== null && bccomp($discount, $voucher->max_discount_amount, 2) > 0) {
            $discount = $voucher->max_discount_amount;
        }
        if (bccomp($discount, $amount, 2) > 0) {
            $discount = $amount;
        }
        if (bccomp($discount, '0', 2) <= 0) {
            $this->conflict('VOUCHER_DISCOUNT_TOO_SMALL');
        }

        return ['id' => $voucher->id, 'code' => $voucher->code, 'name' => $voucher->name,
            'discount_type' => $voucher->discount_type, 'discount_value' => $voucher->discount_value,
            'discount_amount' => $discount, 'minimum_order_amount' => $voucher->minimum_order_amount,
            'fingerprint' => hash('sha256', json_encode([$voucher->id, $voucher->updated_at?->toJSON(),
                $voucher->status, $voucher->starts_at?->toJSON(), $voucher->ends_at?->toJSON(),
                $voucher->total_usage_limit, $voucher->per_buyer_usage_limit, $discount], JSON_THROW_ON_ERROR))];
    }

    public function redeem(SalesOrder $order, string $code): void
    {
        if ($order->sales_channel !== 'retail') {
            $this->conflict('VOUCHER_NOT_AVAILABLE_FOR_DEALER');
        }
        if ($order->sales_voucher_id !== null) {
            $this->conflict('VOUCHER_ALREADY_APPLIED');
        }
        $quote = $this->quote($code, 'retail', $order->buyer_user_id, $order->grand_total, true);
        $items = $order->items()->where('is_gift', false)->orderBy('id')->get();
        $allocations = $this->allocator->allocate($items->map(fn ($item): array => [
            'product_variant_id' => $item->product_variant_id, 'amount' => $item->line_total,
        ])->all(), $quote['discount_amount']);
        foreach ($items as $item) {
            $discount = $allocations[$item->product_variant_id] ?? '0.00';
            $item->update(['discount_amount' => bcadd($item->discount_amount, $discount, 2),
                'line_total' => bcsub($item->line_total, $discount, 2)]);
        }
        $order->update(['sales_voucher_id' => $quote['id'], 'sales_voucher_code_snapshot' => $quote['code'],
            'sales_voucher_discount_snapshot' => $quote['discount_amount'],
            'discount_total' => bcadd($order->discount_total, $quote['discount_amount'], 2),
            'grand_total' => bcsub($order->grand_total, $quote['discount_amount'], 2)]);
        SalesVoucher::query()->findOrFail($quote['id'])->redemptions()->create([
            'sales_order_id' => $order->id, 'buyer_user_id' => $order->buyer_user_id,
            'discount_amount' => $quote['discount_amount'], 'status' => 'redeemed', 'redeemed_at' => now(),
        ]);
    }

    public function release(SalesOrder $order): void
    {
        if ($order->sales_voucher_id === null) {
            return;
        }
        SalesVoucher::query()->whereKey($order->sales_voucher_id)->lockForUpdate()->firstOrFail();
        $redemption = $order->salesVoucherRedemption()->where('status', 'redeemed')->lockForUpdate()->first();
        $redemption?->update(['status' => 'released', 'released_at' => now()]);
    }

    /** @param array<string, mixed> $details */
    private function conflict(string $code, array $details = []): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code, 'details' => $details], 409));
    }
}
