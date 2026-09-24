<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveWarehouseRequest;
use App\Models\Warehouse;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
            'sort' => ['nullable', 'in:name,code,newest'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $query = Warehouse::query();
        if (isset($data['search'])) {
            $query->where(fn ($builder) => $builder->where('name', 'like', '%'.$data['search'].'%')
                ->orWhere('code', 'like', '%'.$data['search'].'%'));
        }
        if (isset($data['status'])) {
            $query->where('status', $data['status']);
        }
        match ($data['sort'] ?? 'name') {
            'code' => $query->orderBy('code'),
            'newest' => $query->latest('id'),
            default => $query->orderBy('name'),
        };

        return response()->json($query->orderBy('id')->paginate($data['per_page'] ?? 20));
    }

    public function store(SaveWarehouseRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        try {
            $warehouse = DB::transaction(function () use ($request, $auditLogger): Warehouse {
                $warehouse = Warehouse::create($request->validated());
                $auditLogger->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_WAREHOUSE, $warehouse, 'Warehouse created', [], $warehouse->only(['code', 'name', 'status', 'is_default_sales', 'is_default_clinic']));

                return $warehouse;
            });
        } catch (QueryException $exception) {
            $this->rethrowWarehouseConflict($exception);
        }

        return response()->json(['data' => $warehouse], 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json(['data' => $warehouse]);
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse, AuditLogger $auditLogger): JsonResponse
    {
        try {
            $warehouse = DB::transaction(function () use ($request, $warehouse, $auditLogger): Warehouse {
                $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
                $data = $request->validated();
                if (isset($data['code']) && $data['code'] !== $warehouse->code
                    && ($warehouse->balances()->exists() || $warehouse->movements()->exists())) {
                    throw new HttpResponseException(response()->json(['code' => 'WAREHOUSE_CODE_IMMUTABLE', 'message' => 'WAREHOUSE_CODE_IMMUTABLE'], 409));
                }
                $before = $warehouse->only(['code', 'name', 'status', 'is_default_sales', 'is_default_clinic']);
                $warehouse->update($data);
                $after = $warehouse->only(['code', 'name', 'status', 'is_default_sales', 'is_default_clinic']);
                $auditLogger->log($before['status'] === 'active' && $after['status'] === 'inactive'
                    ? AuditLogger::ACTION_DEACTIVATE
                    : ($before['status'] === 'inactive' && $after['status'] === 'active' ? AuditLogger::ACTION_ACTIVATE : AuditLogger::ACTION_UPDATE),
                    AuditLogger::MODULE_WAREHOUSE, $warehouse, 'Warehouse updated', $before, $after);

                return $warehouse;
            });
        } catch (QueryException $exception) {
            $this->rethrowWarehouseConflict($exception);
        }

        return response()->json(['data' => $warehouse->refresh()]);
    }

    private function rethrowWarehouseConflict(QueryException $exception): never
    {
        if (($exception->errorInfo[1] ?? null) === 1062) {
            throw new HttpResponseException(response()->json(['code' => 'WAREHOUSE_UNIQUE_CONFLICT', 'message' => 'Warehouse code or active default already exists.'], 409));
        }

        throw $exception;
    }
}
