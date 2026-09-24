<?php

namespace App\Support;

class Sku
{
    public static function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }
}
