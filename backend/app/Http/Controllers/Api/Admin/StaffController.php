<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreStaffRequest;
use App\Http\Requests\Admin\UpdateStaffRequest;
use App\Http\Resources\AdminStaffResource;
use App\Models\User;
use App\Services\StaffAccountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class StaffController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:'.implode(',', User::STAFF_ROLES)],
        ]);

        $staff = User::query()
            ->whereIn('role', User::STAFF_ROLES)
            ->with('doctorProfile')
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(fn (Builder $builder): Builder => $builder
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%'));
            })
            ->when($validated['role'] ?? null, fn (Builder $query, string $role): Builder => $query->where('role', $role))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        return AdminStaffResource::collection($staff);
    }

    public function store(StoreStaffRequest $request, StaffAccountService $staffAccounts): JsonResponse
    {
        $user = $staffAccounts->create($request->safe()->only([
            'name',
            'email',
            'phone',
            'password',
        ]));

        return (new AdminStaffResource($user))
            ->additional(['message' => 'Tạo nhân viên thành công.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(User $user): AdminStaffResource
    {
        abort_unless(in_array($user->role, User::STAFF_ROLES, true), Response::HTTP_NOT_FOUND);

        return new AdminStaffResource($user->load('doctorProfile'));
    }

    public function update(
        UpdateStaffRequest $request,
        User $user,
        StaffAccountService $staffAccounts,
    ): JsonResponse {
        abort_unless($user->role === User::ROLE_RECEPTIONIST, Response::HTTP_NOT_FOUND);

        $updated = $staffAccounts->update($user, $request->safe()->only([
            'name',
            'email',
            'phone',
            'password',
        ]));

        return (new AdminStaffResource($updated))
            ->additional(['message' => 'Cập nhật nhân viên thành công.'])
            ->response();
    }

    public function destroy(User $user, StaffAccountService $staffAccounts): JsonResponse
    {
        abort_unless($user->role === User::ROLE_RECEPTIONIST, Response::HTTP_NOT_FOUND);
        $staffAccounts->delete($user);

        return response()->json(['message' => 'Xóa nhân viên thành công.']);
    }
}
