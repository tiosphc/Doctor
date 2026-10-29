<?php

namespace Tests\Unit\Support;

use App\Support\CustomerCode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CustomerCodeTest extends TestCase
{
    public function test_formats_customer_id_with_an_eight_digit_minimum_width(): void
    {
        $this->assertSame('CUS00000001', CustomerCode::fromId(1));
    }

    public function test_does_not_truncate_customer_id_above_eight_digits(): void
    {
        $this->assertSame('CUS100000000', CustomerCode::fromId(100000000));
    }

    public function test_rejects_non_positive_customer_id(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerCode::fromId(0);
    }
}
