<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\SetupDoctorPasswordRequest;
use App\Services\DoctorAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpFoundation\Response;

class DoctorPasswordSetupController extends Controller
{
    public function __invoke(
        SetupDoctorPasswordRequest $request,
        DoctorAccountService $doctorAccountService,
    ): JsonResponse {
        $status = $doctorAccountService->setupPassword($request->validated());

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'Liên kết thiết lập mật khẩu không hợp lệ hoặc đã hết hạn. Vui lòng liên hệ quản trị viên để được gửi lại email.',
                'errors' => [
                    'token' => ['Liên kết thiết lập mật khẩu không hợp lệ hoặc đã hết hạn.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'message' => 'Mật khẩu đã được thiết lập thành công.',
        ]);
    }
}
