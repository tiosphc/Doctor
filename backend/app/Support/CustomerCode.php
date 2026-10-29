<?php

namespace App\Support;

use InvalidArgumentException;

class CustomerCode
{
    public static function fromId(int $customerId): string
    {
        if ($customerId < 1) {
            throw new InvalidArgumentException('Customer ID must be a positive integer.');
        }

        return 'CUS'.str_pad((string) $customerId, 8, '0', STR_PAD_LEFT);
    }
}
