<?php

namespace App\Services;

class TopUpGatewayStatus
{
    public function __construct(
        public readonly int $orderCode,
        public readonly int $amount,
        public readonly int $amountPaid,
        public readonly int $amountRemaining,
        public readonly string $paymentLinkId,
        public readonly string $status,
        public readonly ?string $reference,
        public readonly ?string $paidAt,
    ) {}
}
