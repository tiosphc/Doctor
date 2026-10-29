<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Mail\GuestBookingOtpMail;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class GuestBookingVerificationService
{
    public function requestOtp(string $email): void
    {
        $email = $this->normalizeEmail($email);
        $otp = (string) random_int(100000, 999999);
        $ttlMinutes = (int) config('booking.guest.otp_ttl_minutes');

        Cache::put($this->otpKey($email), [
            'otp_hash' => Hash::make($otp),
            'attempts' => 0,
            'expires_at' => now()->addMinutes($ttlMinutes)->getTimestamp(),
        ], now()->addMinutes($ttlMinutes));

        Mail::to($email)->send(new GuestBookingOtpMail($otp, $ttlMinutes));
    }

    public function verifyOtp(string $email, string $otp): string
    {
        $email = $this->normalizeEmail($email);
        $key = $this->otpKey($email);

        try {
            return Cache::lock($key.':lock', 10)->block(3, function () use ($email, $key, $otp): string {
                $record = Cache::get($key);

                if (! is_array($record) || ($record['expires_at'] ?? 0) <= now()->getTimestamp()) {
                    Cache::forget($key);
                    $this->throwInvalidVerificationCode();
                }

                if (! Hash::check($otp, $record['otp_hash'])) {
                    $attempts = (int) $record['attempts'] + 1;

                    if ($attempts >= (int) config('booking.guest.otp_max_attempts')) {
                        Cache::forget($key);
                    } else {
                        $record['attempts'] = $attempts;
                        Cache::put($key, $record, now()->setTimestamp($record['expires_at']));
                    }

                    $this->throwInvalidVerificationCode();
                }

                Cache::forget($key);

                $token = Str::random(64);
                Cache::put(
                    $this->tokenKey($token),
                    $email,
                    now()->addMinutes((int) config('booking.guest.verification_token_ttl_minutes')),
                );

                return $token;
            });
        } catch (LockTimeoutException) {
            throw new BusinessConflictException('This verification is already being processed. Please try again.');
        }
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withVerifiedEmail(string $email, string $token, callable $callback): mixed
    {
        $email = $this->normalizeEmail($email);
        $tokenKey = $this->tokenKey($token);

        try {
            return Cache::lock($tokenKey.':lock', 10)->block(3, function () use ($email, $tokenKey, $callback): mixed {
                $verifiedEmail = Cache::get($tokenKey);

                if (! is_string($verifiedEmail) || ! hash_equals($verifiedEmail, $email)) {
                    throw ValidationException::withMessages([
                        'verification_token' => 'The email verification is invalid or has expired.',
                    ]);
                }

                $result = $callback();
                Cache::forget($tokenKey);

                return $result;
            });
        } catch (LockTimeoutException) {
            throw new BusinessConflictException('This verification is already being used. Please try again.');
        }
    }

    private function normalizeEmail(string $email): string
    {
        return Str::lower(trim($email));
    }

    private function otpKey(string $email): string
    {
        return 'guest-booking:otp:'.hash('sha256', $email);
    }

    private function tokenKey(string $token): string
    {
        return 'guest-booking:verified:'.hash('sha256', $token);
    }

    private function throwInvalidVerificationCode(): never
    {
        throw ValidationException::withMessages([
            'otp' => 'The verification code is invalid or has expired.',
        ]);
    }
}
