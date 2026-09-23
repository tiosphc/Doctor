<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Doctor;
use App\Services\DoctorAccountService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class DoctorInvitationController extends Controller
{
    public function __invoke(Doctor $doctor, DoctorAccountService $doctorAccountService): JsonResponse
    {
        $doctor->load('user');

        if ($doctor->user === null) {
            return response()->json([
                'message' => 'Hồ sơ bác sĩ cũ chưa có tài khoản đăng nhập để gửi lời mời.',
            ], Response::HTTP_CONFLICT);
        }

        if (! $doctor->user->must_change_password) {
            return response()->json([
                'message' => 'Tài khoản bác sĩ đã được kích hoạt.',
            ], Response::HTTP_CONFLICT);
        }

        if ($doctor->status !== Doctor::STATUS_ACTIVE) {
            return response()->json([
                'message' => 'Không thể gửi lời mời cho bác sĩ đang tạm ngưng.',
            ], Response::HTTP_CONFLICT);
        }

        if (! $doctorAccountService->queueInvitation($doctor)) {
            return response()->json([
                'message' => 'Chưa thể gửi email thiết lập tài khoản. Vui lòng thử lại sau.',
            ], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return response()->json([
            'message' => 'Đã gửi lại email thiết lập mật khẩu.',
        ]);
    }
}
