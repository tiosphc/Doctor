<?php

namespace App\Support;

use Illuminate\Support\Str;

class Sku
{
    public static function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }

    public static function fromVariantName(string $productSku, string $variantName): string
    {
        $suffix = strtoupper(Str::slug($variantName));

        if ($suffix === '') {
            return '';
        }

        return substr(self::normalize($productSku), 0, 60).'-'.substr($suffix, 0, 39);
    }
}
