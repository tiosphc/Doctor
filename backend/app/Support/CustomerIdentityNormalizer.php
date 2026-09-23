<?php

namespace App\Support;

use Illuminate\Support\Str;

class CustomerIdentityNormalizer
{
    public const VERSION = 'customer-v1';

    public function normalizeEmail(?string $email): ?string
    {
        $email = $this->trim($email);

        if ($email === null) {
            return null;
        }

        $normalized = Str::lower($email);

        return filter_var($normalized, FILTER_VALIDATE_EMAIL) === false
            ? null
            : $normalized;
    }

    public function normalizePhone(?string $phone): ?string
    {
        $phone = $this->trim($phone);

        if ($phone === null) {
            return null;
        }

        $compact = preg_replace('/[\s().-]+/u', '', $phone) ?? '';

        if (str_starts_with($compact, '00')) {
            $compact = '+'.substr($compact, 2);
        }

        if (preg_match('/^\+[1-9][0-9]{7,14}$/', $compact) === 1) {
            return $compact;
        }

        if (preg_match('/^0[0-9]{9}$/', $compact) === 1) {
            return '+84'.substr($compact, 1);
        }

        if (preg_match('/^84[0-9]{9}$/', $compact) === 1) {
            return '+'.$compact;
        }

        return null;
    }

    public function normalizeName(?string $name): ?string
    {
        $name = $this->trim($name);

        if ($name === null) {
            return null;
        }

        return preg_replace('/\s+/u', ' ', $name) ?: null;
    }

    public function displayEmail(?string $email): ?string
    {
        return $this->trim($email);
    }

    public function displayPhone(?string $phone): ?string
    {
        return $this->trim($phone);
    }

    private function trim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = preg_replace('/^\s+|\s+$/u', '', $value) ?? trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
