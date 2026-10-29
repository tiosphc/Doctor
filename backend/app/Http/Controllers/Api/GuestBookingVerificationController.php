<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RequestGuestBookingOtpRequest;
use App\Http\Requests\VerifyGuestBookingOtpRequest;
use App\Services\GuestBookingVerificationService;
use Illuminate\Http\JsonResponse;

class GuestBookingVerificationController extends Controller
{
    public function requestOtp(
        RequestGuestBookingOtpRequest $request,
        GuestBookingVerificationService $verificationService,
    ): JsonResponse {
        $verificationService->requestOtp($request->validated('email'));

        return response()->json(['message' => 'Verification code sent.']);
    }

    public function verifyOtp(
        VerifyGuestBookingOtpRequest $request,
        GuestBookingVerificationService $verificationService,
    ): JsonResponse {
        $validated = $request->validated();

        return response()->json([
            'message' => 'Email verified.',
            'verification_token' => $verificationService->verifyOtp($validated['email'], $validated['otp']),
        ]);
    }
}
