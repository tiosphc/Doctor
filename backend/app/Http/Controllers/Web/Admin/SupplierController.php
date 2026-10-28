<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SaveSupplierRequest;
use App\Models\Supplier;
use App\Services\SupplierWriteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\HttpException;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,inactive'],
        ]);
        $suppliers = Supplier::query()->withCount('purchaseOrders')
            ->when($filters['search'] ?? null, fn ($query, $search) => $query->where(fn ($query) => $query->where('code', 'like', '%'.$search.'%')->orWhere('name', 'like', '%'.$search.'%')))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status));

        return view('admin.suppliers.index', [
            'suppliers' => $suppliers->orderBy('name')->orderBy('id')->paginate(20)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function create(): View
    {
        return view('admin.suppliers.create');
    }

    public function store(SaveSupplierRequest $request, SupplierWriteService $writer): RedirectResponse
    {
        $supplier = $writer->create($request->validated());

        return redirect()->route('admin.web.suppliers.edit', $supplier)->with('success', 'Đã tạo nhà cung cấp.');
    }

    public function edit(Supplier $supplier): View
    {
        return view('admin.suppliers.edit', compact('supplier'));
    }

    public function update(SaveSupplierRequest $request, Supplier $supplier, SupplierWriteService $writer): RedirectResponse
    {
        try {
            $writer->update($supplier, $request->validated());
        } catch (HttpException $exception) {
            if ($exception->getStatusCode() === 409) {
                throw ValidationException::withMessages(['code' => 'Không thể đổi mã nhà cung cấp đã được dùng trong đơn mua.']);
            }
            throw $exception;
        }

        return redirect()->route('admin.web.suppliers.edit', $supplier)->with('success', 'Đã cập nhật nhà cung cấp.');
    }
}
