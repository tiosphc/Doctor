<?php

return [
    'review' => [
        'enabled' => (bool) env('REVIEW_REWARD_ENABLED', true),
        'percent' => (int) env('REVIEW_REWARD_PERCENT', 5),
        'expiry_days' => (int) env('REVIEW_REWARD_EXPIRY_DAYS', 30),
    ],
];
