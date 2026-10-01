<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class PayOsClient implements WalletTopUpGateway
{
    private const ENDPOINT = 'https://api-merchant.payos.vn/v2/payment-requests';

    public function isConfigured(): bool
    {
        return $this->credential('client_id') !== '' && $this->credential('api_key') !== '' && $this->credential('checksum_key') !== '';
    }

    /** @return array{paymentLinkId: string, checkoutUrl: string} */
    public function create(int $orderCode, int $amount, string $returnUrl, int $expiresAt): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PAYOS_NOT_CONFIGURED');
        }

        $description = 'NAP VI';
        $signed = "amount={$amount}&cancelUrl={$returnUrl}&description={$description}&orderCode={$orderCode}&returnUrl={$returnUrl}";
        $payload = [
            'orderCode' => $orderCode,
            'amount' => $amount,
            'description' => $description,
            'cancelUrl' => $returnUrl,
            'returnUrl' => $returnUrl,
            'expiredAt' => $expiresAt,
            'signature' => hash_hmac('sha256', $signed, $this->credential('checksum_key')),
        ];

        try {
            $response = Http::withHeaders(['x-client-id' => $this->credential('client_id'), 'x-api-key' => $this->credential('api_key')])
                ->connectTimeout(5)->timeout(12)->post(self::ENDPOINT, $payload);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('PAYOS_UNAVAILABLE', previous: $exception);
        }

        if (! $response->successful() || $response->json('code') !== '00') {
            throw new RuntimeException('PAYOS_REQUEST_FAILED');
        }

        $payload = $response->json();
        $data = $this->verifiedSignedData(is_array($payload) ? $payload : null);
        if ($data === null
            || filter_var($data['orderCode'] ?? null, FILTER_VALIDATE_INT) !== $orderCode
            || filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT) !== $amount
            || ($data['currency'] ?? null) !== 'VND'
            || ! is_string($data['paymentLinkId'] ?? null)
            || ($data['paymentLinkId'] ?? '') === ''
            || ($data['status'] ?? null) !== 'PENDING'
            || ! is_string($data['checkoutUrl'] ?? null)
            || parse_url($data['checkoutUrl'], PHP_URL_SCHEME) !== 'https'
            || parse_url($data['checkoutUrl'], PHP_URL_HOST) !== 'pay.payos.vn') {
            throw new RuntimeException('PAYOS_RESPONSE_INVALID');
        }

        return ['paymentLinkId' => $data['paymentLinkId'], 'checkoutUrl' => $data['checkoutUrl']];
    }

    /** @return array{paymentLinkId: string, checkoutUrl: string}|null */
    public function findExisting(int $orderCode, int $amount): ?array
    {
        try {
            $status = $this->fetch($orderCode, $amount);
        } catch (RuntimeException) {
            return null;
        }

        if (! in_array($status->status, ['pending', 'paid'], true)) {
            return null;
        }

        return ['paymentLinkId' => $status->paymentLinkId, 'checkoutUrl' => 'https://pay.payos.vn/web/'.$status->paymentLinkId];
    }

    public function fetch(int $orderCode, int $amount): TopUpGatewayStatus
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('PAYOS_NOT_CONFIGURED');
        }

        try {
            $response = Http::withHeaders(['x-client-id' => $this->credential('client_id'), 'x-api-key' => $this->credential('api_key')])
                ->connectTimeout(5)->timeout(12)->get(self::ENDPOINT.'/'.$orderCode);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('PAYOS_UNAVAILABLE', previous: $exception);
        }

        if (! $response->successful() || $response->json('code') !== '00') {
            throw new RuntimeException('PAYOS_LOOKUP_FAILED');
        }
        $payload = $response->json();
        $data = $this->verifiedSignedData(is_array($payload) ? $payload : null);
        if ($data === null
            || filter_var($data['orderCode'] ?? null, FILTER_VALIDATE_INT) !== $orderCode
            || filter_var($data['amount'] ?? null, FILTER_VALIDATE_INT) !== $amount
            || ! is_string($data['id'] ?? null) || ! preg_match('/^[a-zA-Z0-9-]+$/', $data['id'])) {
            throw new RuntimeException('PAYOS_RESPONSE_INVALID');
        }
        $paid = filter_var($data['amountPaid'] ?? null, FILTER_VALIDATE_INT);
        $remaining = filter_var($data['amountRemaining'] ?? null, FILTER_VALIDATE_INT);
        if ($paid === false || $remaining === false || $paid < 0 || $remaining < 0 || $paid + $remaining !== $amount) {
            throw new RuntimeException('PAYOS_RESPONSE_INVALID');
        }
        $status = match ($data['status'] ?? null) {
            'PENDING', 'PROCESSING', 'UNDERPAID' => 'pending',
            'PAID' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED' => 'failed',
            'CANCELLED' => 'cancelled',
            default => throw new RuntimeException('PAYOS_STATUS_UNKNOWN'),
        };
        $reference = null;
        $paidAt = null;
        if ($status === 'paid') {
            $transactions = $data['transactions'] ?? null;
            if ($paid !== $amount || $remaining !== 0 || ! is_array($transactions) || $transactions === []) {
                throw new RuntimeException('PAYOS_PAID_TRANSACTIONS_INVALID');
            }
            $transactionTotal = 0;
            $references = [];
            foreach ($transactions as $transaction) {
                if (! is_array($transaction)
                    || filter_var($transaction['amount'] ?? null, FILTER_VALIDATE_INT) === false
                    || ! is_string($transaction['reference'] ?? null) || trim($transaction['reference']) === '') {
                    throw new RuntimeException('PAYOS_PAID_TRANSACTIONS_INVALID');
                }
                $transactionTotal += (int) $transaction['amount'];
                $references[] = trim($transaction['reference']);
            }
            if ($transactionTotal !== $amount || count(array_unique($references)) !== count($references)) {
                throw new RuntimeException('PAYOS_PAID_TRANSACTIONS_INVALID');
            }
            $transaction = array_values($transactions)[count($transactions) - 1];
            $reference = trim($transaction['reference']);
            $paidAt = is_string($transaction['transactionDateTime'] ?? null) ? $transaction['transactionDateTime'] : null;
        }

        return new TopUpGatewayStatus($orderCode, $amount, $paid, $remaining, $data['id'], $status, $reference, $paidAt);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed>|null */
    public function verifiedWebhookData(array $payload): ?array
    {
        return $this->verifiedSignedData($payload);
    }

    /** @param array<string, mixed>|null $payload @return array<string, mixed>|null */
    private function verifiedSignedData(?array $payload): ?array
    {
        if ($payload === null) {
            return null;
        }
        $data = $payload['data'] ?? null;
        $signature = $payload['signature'] ?? null;
        if (! $this->isConfigured() || ! is_array($data) || ! is_string($signature) || ! preg_match('/^[a-f0-9]{64}$/i', $signature)) {
            return null;
        }

        ksort($data, SORT_STRING);
        $parts = [];
        foreach ($data as $key => $value) {
            if (! is_string($key) || (! is_scalar($value) && $value !== null && ! is_array($value))) {
                return null;
            }
            $parts[] = $key.'='.$this->signatureValue($value);
        }

        $expected = hash_hmac('sha256', implode('&', $parts), $this->credential('checksum_key'));

        return hash_equals($expected, strtolower($signature)) ? $data : null;
    }

    private function credential(string $name): string
    {
        return trim((string) config("services.payos.{$name}"));
    }

    private function signatureValue(mixed $value): string
    {
        if ($value === null || $value === 'null' || $value === 'undefined') {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            $sorted = array_map(function (mixed $item): mixed {
                if (is_array($item)) {
                    ksort($item, SORT_STRING);
                }

                return $item;
            }, $value);

            return json_encode($sorted, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return (string) $value;
    }
}
