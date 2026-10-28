<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogIndexRequest;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuditLogReadService;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(AuditLogIndexRequest $request, AuditLogReadService $logs): View
    {
        $filters = $request->validated();

        return view('admin.audit-logs.index', [
            'logs' => $logs->filtered($filters)->paginate($filters['per_page'] ?? 25)->withQueryString(),
            'filters' => $filters,
            'modules' => AuditLogger::MODULES,
            'actions' => AuditLogger::ACTIONS,
            'roles' => [...User::ROLES, 'guest'],
        ]);
    }

    public function show(AuditLog $auditLog, AuditLogger $logger): View
    {
        return view('admin.audit-logs.show', [
            'auditLog' => $auditLog,
            'oldValues' => $logger->sanitize($auditLog->old_values ?? []),
            'newValues' => $logger->sanitize($auditLog->new_values ?? []),
            'metadata' => $logger->sanitize($auditLog->metadata ?? []),
        ]);
    }
}
