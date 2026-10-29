<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['active', 'inactive'])], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $query = Supplier::query();
        if (isset($filters['search'])) {
            $query->where(fn ($builder) => $builder->where('code', 'like', '%'.$filters['search'].'%')->orWhere('name', 'like', '%'.$filters['search'].'%'));
        }
        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($filters['per_page'] ?? 20));
    }

    public function store(SaveSupplierRequest $request, SupplierWriteService $writer): JsonResponse
    {
        $supplier = $writer->create($request->validated());

        return response()->json(['data' => $supplier], 201);
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return response()->json(['data' => $supplier]);
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier, SupplierWriteService $writer): JsonResponse
    {
        $supplier = $writer->update($supplier, $request->validated());

        return response()->json(['data' => $supplier]);
    }
}
