<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogIndexRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Services\AuditLogReadService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request, AuditLogReadService $logs): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $paginated = $logs->filtered($validated)
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return AuditLogResource::collection($paginated);
    }

    public function show(AuditLog $auditLog): AuditLogResource
    {
        return new AuditLogResource($auditLog);
    }
}
