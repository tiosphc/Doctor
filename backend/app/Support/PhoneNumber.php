<?php

namespace App\Support;

class PhoneNumber
{
    public static function normalize(?string $phone): string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';

        if (str_starts_with($digits, '84') && strlen($digits) === 11) {
            return '0'.substr($digits, 2);
        }

        return $digits;
    }
}
