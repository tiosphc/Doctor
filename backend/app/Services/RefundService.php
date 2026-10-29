<?php

namespace App\Services;

use App\Models\PaymentAllocation;
use App\Models\Refund;
use App\Models\SalesOrder;
use App\Models\SalesReturn;
use App\Notifications\SalesReturnNotification;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RefundService
{
    public function __construct(
        private readonly OrderPaymentSummaryService $summaries,
        private readonly AuditLogger $audit,
        private readonly DealerWalletService $wallets,
    ) {}

    /** @param array<string, mixed> $data */
    public function complete(SalesOrder $order, array $data, int $actorId): Refund
    {
        $amount = (string) $data['amount'];
        if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/', $amount) !== 1 || bccomp($amount, '0', 2) <= 0) {
            $this->conflict('REFUND_AMOUNT_INVALID');
        }
        $amount = bcadd($amount, '0', 2);
        $reference = trim((string) ($data['external_reference'] ?? '')) ?: null;
        $normalizedReference = $reference === null ? null : mb_strtoupper($reference);
        $note = trim((string) ($data['note'] ?? '')) ?: null;
        $returnId = isset($data['return_id']) ? (int) $data['return_id'] : null;
        if ($data['reason'] === 'return' && $returnId === null) {
            $this->conflict('RETURN_NOT_AVAILABLE');
        }
        $key = $data['operation_key'];
        $fingerprint = hash('sha256', json_encode([$order->id, $amount, $data['refund_method'], $data['reason'], $returnId, $normalizedReference, $note], JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(function () use ($order, $data, $actorId, $amount, $reference, $normalizedReference, $note, $returnId, $key, $fingerprint): Refund {
                $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
                $existing = Refund::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $locked, $fingerprint);
                }
                $summary = $this->summaries->summary($locked);
                if ($data['refund_method'] === 'dealer_wallet' && ($locked->sales_channel !== 'dealer' || $locked->dealer_account_id === null)) {
                    $this->conflict('DEALER_WALLET_REFUND_REQUIRED');
                }
                if ($locked->payment_method === 'dealer_wallet' && $data['refund_method'] !== 'dealer_wallet') {
                    $this->conflict('DEALER_WALLET_REFUND_REQUIRED');
                }
                if ($this->summaries->hasUnsupportedLegacyMarker($locked, $summary['paid_amount'])) {
                    $this->conflict('PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW');
                }
                if (bccomp($summary['paid_amount'], '0', 2) === 0) {
                    $this->conflict('NOTHING_TO_REFUND');
                }
                if (bccomp($amount, $summary['refundable_amount'], 2) > 0) {
                    $this->conflict('REFUND_EXCEEDS_REFUNDABLE_AMOUNT');
                }
                $linkedReturn = null;
                if ($returnId !== null) {
                    $linkedReturn = SalesReturn::query()->whereKey($returnId)->where('sales_order_id', $locked->id)
                        ->where('status', 'completed')->first();
                    if ($linkedReturn === null) {
                        $this->conflict('RETURN_NOT_AVAILABLE');
                    }
                    $returnValue = '0.00';
                    foreach ($linkedReturn->items as $item) {
                        $returnValue = bcadd($returnValue, $item->return_value_snapshot
                            ?? bcadd(bcmul($item->quantity, $item->unit_value_snapshot, 5), '0.005', 2), 2);
                    }
                    $used = bcadd((string) $linkedReturn->refunds()->where('status', 'completed')->sum('amount'), '0', 2);
                    if (bccomp($amount, bcsub($returnValue, $used, 2), 2) > 0) {
                        $this->conflict('REFUND_EXCEEDS_RETURN_VALUE');
                    }
                }
                if ($normalizedReference !== null && Refund::query()->where('refund_method', $data['refund_method'])
                    ->where('external_reference_normalized', $normalizedReference)->exists()) {
                    $this->conflict('REFUND_EXTERNAL_REFERENCE_ALREADY_USED');
                }
                $allocations = PaymentAllocation::query()->with('payment')->where('sales_order_id', $locked->id)
                    ->whereHas('payment', fn ($query) => $query->where('status', 'settled'))
                    ->orderBy('id')->lockForUpdate()->get();
                $remaining = $amount;
                $parts = [];
                foreach ($allocations as $allocation) {
                    if ($allocation->payment->currency !== $locked->currency) {
                        $this->conflict('PAYMENT_CURRENCY_MISMATCH');
                    }
                    $already = bcadd((string) DB::table('refund_allocations as allocation')
                        ->join('refunds as refund', 'refund.id', '=', 'allocation.refund_id')
                        ->where('allocation.payment_allocation_id', $allocation->id)
                        ->where('refund.status', 'completed')->sum('allocation.amount'), '0', 2);
                    $available = bcsub($allocation->allocated_amount, $already, 2);
                    if (bccomp($available, '0', 2) <= 0) {
                        continue;
                    }
                    $part = bccomp($available, $remaining, 2) < 0 ? $available : $remaining;
                    $parts[] = [$allocation->id, $part];
                    $remaining = bcsub($remaining, $part, 2);
                    if (bccomp($remaining, '0', 2) === 0) {
                        break;
                    }
                }
                if (bccomp($remaining, '0', 2) !== 0) {
                    $this->conflict('REFUND_ALLOCATION_UNAVAILABLE');
                }
                $refund = Refund::create([
                    'refund_code' => 'REF'.Str::upper((string) Str::ulid()),
                    'sales_order_id' => $locked->id, 'sales_return_id' => $linkedReturn?->id,
                    'currency' => $locked->currency, 'amount' => $amount,
                    'refund_method' => $data['refund_method'], 'status' => 'pending',
                    'reason_code' => $data['reason'], 'note' => $note,
                    'external_reference' => $reference, 'external_reference_normalized' => $normalizedReference,
                    'processed_by_user_id' => $actorId, 'operation_key' => $key,
                    'request_fingerprint' => $fingerprint,
                ]);
                foreach ($parts as [$allocationId, $part]) {
                    $refund->allocations()->create(['payment_allocation_id' => $allocationId, 'amount' => $part]);
                }
                $refund->update(['status' => 'completed', 'completed_at' => now()]);
                if ($refund->refund_method === 'dealer_wallet') {
                    $this->wallets->creditRefund($refund, $actorId);
                }
                $newRefunded = bcadd($summary['refunded_amount'], $amount, 2);
                $locked->forceFill(['refund_status' => $this->summaries->refundStatus($newRefunded,
                    bcsub($summary['paid_amount'], $newRefunded, 2))])->save();
                $this->audit->log(AuditLogger::ACTION_REFUND_COMPLETED, AuditLogger::MODULE_REFUND, $refund,
                    'Manual refund completed', metadata: ['sales_order_id' => $locked->id, 'refund_code' => $refund->refund_code,
                        'amount' => $amount, 'return_id' => $linkedReturn?->id, 'actor_id' => $actorId]);
                if ($linkedReturn !== null && in_array($linkedReturn->request_source, ['dealer', 'retail'], true)) {
                    $linkedReturn->requestedBy?->notify(new SalesReturnNotification($linkedReturn, 'refunded', amount: $amount));
                }

                return $refund->load('allocations.paymentAllocation.payment');
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = Refund::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $order, $fingerprint);
                }
                if ($normalizedReference !== null) {
                    $this->conflict('REFUND_EXTERNAL_REFERENCE_ALREADY_USED');
                }
            }
            throw $exception;
        }
    }

    private function replay(Refund $refund, SalesOrder $order, string $fingerprint): Refund
    {
        if ($refund->sales_order_id !== $order->id || $refund->request_fingerprint !== $fingerprint) {
            $this->conflict('REFUND_OPERATION_CONFLICT');
        }

        return $refund->load('allocations.paymentAllocation.payment');
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
