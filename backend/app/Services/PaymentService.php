<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\SalesOrder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PaymentService
{
    public function __construct(
        private readonly OrderPaymentSummaryService $summaries,
        private readonly AuditLogger $audit,
        private readonly SalesOrderService $orders,
    ) {}

    /** @param array<string, mixed> $data */
    public function recordSettledPayment(SalesOrder $order, array $data, int $actorId): Payment
    {
        $amount = $this->amount((string) $data['amount']);
        $reference = isset($data['external_reference']) ? trim($data['external_reference']) : null;
        $reference = $reference === '' ? null : $reference;
        $normalizedReference = $reference === null ? null : mb_strtoupper($reference);
        $note = isset($data['note']) ? trim($data['note']) : null;
        $note = $note === '' ? null : $note;
        $fingerprint = hash('sha256', json_encode([$order->id, $amount, $data['payment_method'], $normalizedReference, $note], JSON_THROW_ON_ERROR));
        $key = $data['operation_key'];

        try {
            return DB::transaction(function () use ($order, $data, $actorId, $amount, $reference, $normalizedReference, $note, $fingerprint, $key): Payment {
                $locked = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
                $existing = Payment::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $locked, $fingerprint);
                }
                if (in_array($locked->order_status, ['draft', 'cancelled'], true)) {
                    $this->conflict('ORDER_INVALID_STATE');
                }
                $recordedPaid = $this->summaries->paid($locked);
                if ($this->summaries->hasUnsupportedLegacyMarker($locked, $recordedPaid)) {
                    $this->conflict('PAYMENT_LEGACY_STATUS_REQUIRES_REVIEW');
                }
                if ($locked->paymentAllocations()->whereHas('payment',
                    fn ($query) => $query->where('currency', '!=', $locked->currency))->exists()) {
                    $this->conflict('PAYMENT_CURRENCY_MISMATCH');
                }
                $summary = $this->summaries->summary($locked);
                if (bccomp($summary['outstanding_amount'], '0', 2) <= 0) {
                    $this->conflict('ORDER_ALREADY_PAID');
                }
                if (bccomp($amount, $summary['outstanding_amount'], 2) > 0) {
                    $this->conflict('PAYMENT_EXCEEDS_OUTSTANDING');
                }
                if ($normalizedReference !== null && Payment::query()->where('payment_method', $data['payment_method'])
                    ->where('external_reference_normalized', $normalizedReference)->exists()) {
                    $this->conflict('PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED');
                }
                $payment = Payment::create([
                    'payment_code' => 'PAY'.Str::upper((string) Str::ulid()),
                    'payment_context' => $locked->sales_channel,
                    'dealer_account_id' => $locked->sales_channel === 'dealer' ? $locked->dealer_account_id : null,
                    'payer_user_id' => null,
                    'currency' => $locked->currency,
                    'amount' => $amount,
                    'payment_method' => $data['payment_method'],
                    'status' => 'pending',
                    'external_reference' => $reference,
                    'external_reference_normalized' => $normalizedReference,
                    'note' => $note,
                    'recorded_by_user_id' => $actorId,
                    'operation_key' => $key,
                    'request_fingerprint' => $fingerprint,
                    'settled_at' => null,
                ]);
                $payment->allocations()->create(['sales_order_id' => $locked->id, 'allocated_amount' => $amount]);
                $payment->update(['status' => 'settled', 'settled_at' => now()]);
                $paid = bcadd($summary['paid_amount'], $amount, 2);
                $refunded = $summary['refunded_amount'];
                $locked->forceFill([
                    'payment_status' => $this->summaries->status((string) $locked->grand_total, $paid),
                    'refund_status' => $this->summaries->refundStatus($refunded, bcsub($paid, $refunded, 2)),
                ])->save();
                $this->orders->completeIfPaid($locked, $actorId);
                $this->audit->log(AuditLogger::ACTION_PAYMENT_RECORDED, AuditLogger::MODULE_PAYMENT, $payment, 'Manual received payment recorded',
                    metadata: ['payment_code' => $payment->payment_code, 'sales_order_id' => $locked->id,
                        'order_code' => $locked->order_code, 'amount' => $amount,
                        'payment_method' => $payment->payment_method, 'external_reference' => $reference, 'actor_id' => $actorId]);
                if ($locked->sales_channel === 'dealer' && $locked->dealer_account_id !== null) {
                    $accountId = $locked->dealer_account_id;
                    DB::afterCommit(static function () use ($accountId): void {
                        try {
                            app(DealerAutoTierService::class)->upgradeIfEligible($accountId);
                        } catch (\Throwable $exception) {
                            report($exception);
                        }
                    });
                }

                return $payment->load('allocations', 'recorder:id,name');
            }, 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 1062) {
                $existing = Payment::query()->where('operation_key', $key)->first();
                if ($existing !== null) {
                    return $this->replay($existing, $order, $fingerprint);
                }
                if ($normalizedReference !== null && Payment::query()->where('payment_method', $data['payment_method'])
                    ->where('external_reference_normalized', $normalizedReference)->exists()) {
                    $this->conflict('PAYMENT_EXTERNAL_REFERENCE_ALREADY_USED');
                }
            }
            throw $exception;
        }
    }

    private function amount(string $input): string
    {
        if (preg_match('/^(?:0|[1-9][0-9]{0,15})(?:\.[0-9]{1,2})?$/', $input) !== 1
            || bccomp($input, '0', 2) <= 0) {
            $this->conflict('PAYMENT_AMOUNT_INVALID');
        }

        return bcadd($input, '0', 2);
    }

    private function replay(Payment $payment, SalesOrder $order, string $fingerprint): Payment
    {
        if ($payment->request_fingerprint !== $fingerprint
            || ! $payment->allocations()->where('sales_order_id', $order->id)->exists()) {
            $this->conflict('PAYMENT_OPERATION_CONFLICT');
        }

        return $payment->load('allocations', 'recorder:id,name');
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
