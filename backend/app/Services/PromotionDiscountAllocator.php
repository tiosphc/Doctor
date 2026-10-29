<?php

namespace App\Services;

class PromotionDiscountAllocator
{
    /** @param list<array{product_variant_id: int, amount: string}> $eligible
     * @return array<int, string>
     */
    public function allocate(array $eligible, string $discount): array
    {
        $total = '0';
        foreach ($eligible as $line) {
            $total = bcadd($total, bcmul($line['amount'], '100', 0), 0);
        }
        $discountCents = bcmul($discount, '100', 0);
        if ($total === '0' || bccomp($discountCents, '0', 0) === 0) {
            return array_fill_keys(array_column($eligible, 'product_variant_id'), '0.00');
        }
        $allocated = '0';
        $parts = [];
        foreach ($eligible as $line) {
            $cents = bcmul($line['amount'], '100', 0);
            $numerator = bcmul($discountCents, $cents, 0);
            $base = bcdiv($numerator, $total, 0);
            $parts[] = ['id' => $line['product_variant_id'], 'cents' => $base,
                'remainder' => bcmod($numerator, $total)];
            $allocated = bcadd($allocated, $base, 0);
        }
        usort($parts, static function (array $left, array $right): int {
            $remainder = bccomp($right['remainder'], $left['remainder'], 0);

            return $remainder !== 0 ? $remainder : $left['id'] <=> $right['id'];
        });
        $leftover = (int) bcsub($discountCents, $allocated, 0);
        for ($index = 0; $index < $leftover; $index++) {
            $parts[$index]['cents'] = bcadd($parts[$index]['cents'], '1', 0);
        }
        $result = [];
        foreach ($parts as $part) {
            $result[$part['id']] = bcdiv($part['cents'], '100', 2);
        }

        return $result;
    }
}
