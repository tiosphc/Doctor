<?php

namespace App\Services;

interface WalletTopUpGateway
{
    public function isConfigured(): bool;

    /** @return array{paymentLinkId: string, checkoutUrl: string} */
    public function create(int $orderCode, int $amount, string $returnUrl, int $expiresAt): array;

    /** @return array{paymentLinkId: string, checkoutUrl: string}|null */
    public function findExisting(int $orderCode, int $amount): ?array;

    public function fetch(int $orderCode, int $amount): TopUpGatewayStatus;

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    public function verifiedWebhookData(array $payload): ?array;
}
