<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveWarehouseRequest;
use App\Models\Warehouse;
use App\Services\WarehouseWriteService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function store(SaveWarehouseRequest $request, WarehouseWriteService $writer): JsonResponse
    {
        try {
            $warehouse = $writer->create($request->validated());
        } catch (QueryException $exception) {
            $this->rethrowWarehouseConflict($exception);
        }

        return response()->json(['data' => $warehouse], 201);
    }

    public function show(Warehouse $warehouse): JsonResponse
    {
        return response()->json(['data' => $warehouse]);
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse, WarehouseWriteService $writer): JsonResponse
    {
        try {
            $warehouse = $writer->update($warehouse, $request->validated());
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
