<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

class ReportPeriod
{
    public readonly CarbonImmutable $start;

    public readonly CarbonImmutable $endExclusive;

    public readonly string $channel;

    public readonly ?int $warehouseId;

    public readonly ?int $supplierId;

    public readonly ?int $dealerId;

    /** @param array<string, mixed> $filters */
    public function __construct(array $filters)
    {
        $timezone = 'Asia/Ho_Chi_Minh';
        $today = CarbonImmutable::now($timezone);
        $this->start = CarbonImmutable::parse($filters['from'] ?? $today->startOfMonth()->toDateString(), $timezone)->startOfDay();
        $this->endExclusive = CarbonImmutable::parse($filters['to'] ?? $today->toDateString(), $timezone)->addDay()->startOfDay();
        $this->channel = $filters['channel'] ?? 'all';
        $this->warehouseId = isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null;
        $this->supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $this->dealerId = isset($filters['dealer_id']) ? (int) $filters['dealer_id'] : null;
    }

    public function on(Builder $query, string $column): Builder
    {
        return $query->where($column, '>=', $this->start->toDateTimeString())
            ->where($column, '<', $this->endExclusive->toDateTimeString());
    }

    /** @return array{from: string, to: string, timezone: string, channel: string, warehouse_id: ?int, supplier_id: ?int, dealer_id: ?int} */
    public function metadata(): array
    {
        return [
            'from' => $this->start->toDateString(),
            'to' => $this->endExclusive->subDay()->toDateString(),
            'timezone' => 'Asia/Ho_Chi_Minh',
            'channel' => $this->channel,
            'warehouse_id' => $this->warehouseId,
            'supplier_id' => $this->supplierId,
            'dealer_id' => $this->dealerId,
        ];
    }
}
