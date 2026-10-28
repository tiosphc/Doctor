<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;

class AuditLogReadService
{
    /** @param array<string, mixed> $filters */
    public function filtered(array $filters): Builder
    {
        return AuditLog::query()
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('actor_name', 'like', '%'.$search.'%')
                        ->orWhere('target_name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['actor_id'] ?? null, fn (Builder $query, int $actorId): Builder => $query->where('actor_id', $actorId))
            ->when($filters['role'] ?? null, fn (Builder $query, string $role): Builder => $query->where('actor_role', $role))
            ->when($filters['module'] ?? null, fn (Builder $query, string $module): Builder => $query->where('module', $module))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action): Builder => $query->where('action', $action))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
}
