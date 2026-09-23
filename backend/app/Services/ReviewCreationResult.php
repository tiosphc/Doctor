<?php

namespace App\Services;

use App\Models\Review;
use App\Models\Voucher;

class ReviewCreationResult
{
    public function __construct(
        public readonly Review $review,
        public readonly ?Voucher $voucher,
    ) {}
}
