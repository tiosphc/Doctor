<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\Doctor;
use App\Models\User;
use App\Services\RegisteredCustomerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends Controller
{
    public function register(
        RegisterRequest $request,
        RegisteredCustomerService $customerService,
    ): JsonResponse {
        $validated = $request->validated();

        $user = DB::transaction(function () use ($validated, $customerService): User {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'phone' => $validated['phone'] ?? null,
                'password' => Hash::make($validated['password']),
                'role' => User::ROLE_CUSTOMER,
            ]);
            $customerService->ensureForUser($user);

            return $user;
        }, 3);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return (new UserResource($user))
            ->additional(['message' => 'Registration successful.'])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        if (! Auth::guard('web')->attempt($request->safe()->only(['email', 'password']))) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = Auth::guard('web')->user();

        if ($user instanceof User && $user->isDoctor()) {
            if ($user->must_change_password) {
                $this->rejectLogin($request);

                return response()->json([
                    'message' => 'Tài khoản bác sĩ chưa hoàn tất thiết lập mật khẩu. Vui lòng sử dụng email mời hoặc liên hệ quản trị viên.',
                    'requires_password_setup' => true,
                ], Response::HTTP_FORBIDDEN);
            }

            if ($user->doctorProfile?->status !== Doctor::STATUS_ACTIVE) {
                $this->rejectLogin($request);

                return response()->json([
                    'message' => 'Tài khoản bác sĩ hiện không hoạt động. Vui lòng liên hệ quản trị viên.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $request->session()->regenerate();

        return (new UserResource(Auth::guard('web')->user()))
            ->additional(['message' => 'Login successful.'])
            ->response();
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'Logout successful.']);
    }

    public function user(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function updateProfile(
        UpdateProfileRequest $request,
        RegisteredCustomerService $customerService,
    ): JsonResponse {
        $user = $request->user();
        DB::transaction(function () use ($user, $request, $customerService): void {
            $user->update($request->safe()->only(['name', 'phone']));

            if ($user->isCustomer()) {
                $customerService->synchronizeFromUser($user->refresh());
            }
        }, 3);

        return (new UserResource($user->refresh()))
            ->additional(['message' => 'Profile updated successfully.'])
            ->response();
    }

    private function rejectLogin(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
