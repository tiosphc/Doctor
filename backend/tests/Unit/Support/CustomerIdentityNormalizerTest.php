<?php

namespace Tests\Unit\Support;

use App\Support\CustomerIdentityNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CustomerIdentityNormalizerTest extends TestCase
{
    #[DataProvider('emailCases')]
    public function test_normalizes_email_deterministically(?string $input, ?string $expected): void
    {
        $normalizer = new CustomerIdentityNormalizer;

        $this->assertSame($expected, $normalizer->normalizeEmail($input));
        $this->assertSame($expected, $normalizer->normalizeEmail($input));
    }

    #[DataProvider('phoneCases')]
    public function test_normalizes_phone_deterministically(?string $input, ?string $expected): void
    {
        $normalizer = new CustomerIdentityNormalizer;

        $this->assertSame($expected, $normalizer->normalizePhone($input));
        $this->assertSame($expected, $normalizer->normalizePhone($input));
    }

    public function test_normalizes_name_without_using_it_as_an_identity_key(): void
    {
        $normalizer = new CustomerIdentityNormalizer;

        $this->assertSame('Nguyá»…n VÄƒn A', $normalizer->normalizeName("  Nguyá»…n  \t VÄƒn\nA  "));
        $this->assertNull($normalizer->normalizeName(" \t\n "));
    }

    /** @return array<string, array{?string, ?string}> */
    public static function emailCases(): array
    {
        return [
            'trim and lowercase' => ['  Customer@Example.COM ', 'customer@example.com'],
            'plus tag remains distinct' => ['john.smith+promo@gmail.com', 'john.smith+promo@gmail.com'],
            'blank becomes null' => ['   ', null],
            'null remains null' => [null, null],
            'invalid becomes null' => ['not-an-email', null],
        ];
    }

    /** @return array<string, array{?string, ?string}> */
    public static function phoneCases(): array
    {
        return [
            'Vietnam local' => ['0912345678', '+84912345678'],
            'Vietnam international' => ['+84 912 345 678', '+84912345678'],
            'Vietnam country code without plus' => ['84912345678', '+84912345678'],
            'international 00 prefix' => ['0084912345678', '+84912345678'],
            'blank becomes null' => ['  ', null],
            'null remains null' => [null, null],
            'invalid is not guessed' => ['0912-EXT-123', null],
        ];
    }
}
