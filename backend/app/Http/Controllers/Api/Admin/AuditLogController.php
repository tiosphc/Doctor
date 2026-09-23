<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $logs = AuditLog::query()
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $searchQuery) use ($search): void {
                    $searchQuery->where('actor_name', 'like', '%'.$search.'%')
                        ->orWhere('target_name', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($validated['actor_id'] ?? null, fn (Builder $query, int $actorId): Builder => $query->where('actor_id', $actorId))
            ->when($validated['role'] ?? null, fn (Builder $query, string $role): Builder => $query->where('actor_role', $role))
            ->when($validated['module'] ?? null, fn (Builder $query, string $module): Builder => $query->where('module', $module))
            ->when($validated['action'] ?? null, fn (Builder $query, string $action): Builder => $query->where('action', $action))
            ->when($validated['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('created_at', '>=', $from))
            ->when($validated['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return AuditLogResource::collection($logs);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog);
    }
}
