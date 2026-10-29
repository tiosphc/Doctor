<?php

use App\Models\Voucher;

return [
    'enabled' => (bool) env('LOYALTY_ENABLED', true),
    'voucher_expiry_days' => (int) env('LOYALTY_VOUCHER_EXPIRY_DAYS', 30),
    'milestones' => [
        10 => [
            'type' => Voucher::TYPE_PERCENTAGE,
            'value' => 25,
        ],
    ],
];
