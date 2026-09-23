<?php

namespace App\Providers;

use App\Models\Doctor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $email = Str::transliterate(Str::lower($request->string('email')->toString()));

            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('register', fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip()));

        RateLimiter::for('doctor-password-setup', fn (Request $request): array => [
            Limit::perMinute(10)
                ->by('ip:'.$request->ip())
                ->response(fn () => $this->rateLimitedResponse()),
            Limit::perMinute(5)
                ->by('email:'.$this->emailHash($request))
                ->response(fn () => $this->rateLimitedResponse()),
        ]);

        RateLimiter::for('doctor-invitation', function (Request $request): Limit {
            $doctor = $request->route('doctor');
            $doctorKey = $doctor instanceof Doctor ? $doctor->getRouteKey() : (string) $doctor;

            return Limit::perMinute(3)
                ->by('admin:'.$request->user()?->getAuthIdentifier().'|doctor:'.$doctorKey)
                ->response(fn () => $this->rateLimitedResponse());
        });

        RateLimiter::for('guest-otp-request', fn (Request $request): array => [
            Limit::perMinutes(10, (int) config('booking.guest.rate_limits.otp_request_ip'))
                ->by('ip:'.$request->ip())
                ->response(fn () => $this->rateLimitedResponse()),
            Limit::perMinutes(10, (int) config('booking.guest.rate_limits.otp_request_email'))
                ->by('email:'.$this->emailHash($request))
                ->response(fn () => $this->rateLimitedResponse()),
        ]);

        RateLimiter::for('guest-otp-verify', fn (Request $request): Limit => Limit::perMinutes(
            10,
            (int) config('booking.guest.rate_limits.otp_verify_ip'),
        )->by($request->ip())->response(fn () => $this->rateLimitedResponse()));

        RateLimiter::for('guest-booking', function (Request $request) {
            if ($request->user() !== null) {
                return Limit::none();
            }

            return [
                Limit::perHour((int) config('booking.guest.rate_limits.booking_ip'))
                    ->by('ip:'.$request->ip())
                    ->response(fn () => $this->rateLimitedResponse()),
                Limit::perHour((int) config('booking.guest.rate_limits.booking_email'))
                    ->by('email:'.$this->emailHash($request))
                    ->response(fn () => $this->rateLimitedResponse()),
            ];
        });

        RateLimiter::for('guest-lookup', fn (Request $request): Limit => Limit::perMinutes(
            10,
            (int) config('booking.guest.rate_limits.lookup_ip'),
        )->by($request->ip())->response(fn () => $this->rateLimitedResponse()));

        RateLimiter::for('guest-reschedule', fn (Request $request): Limit => Limit::perMinutes(
            10,
            (int) config('booking.guest.rate_limits.reschedule_ip'),
        )->by($request->ip())->response(fn () => $this->rateLimitedResponse()));

        RateLimiter::for('guest-cancel', fn (Request $request): Limit => Limit::perMinutes(
            10,
            (int) config('booking.guest.rate_limits.cancel_ip'),
        )->by($request->ip())->response(fn () => $this->rateLimitedResponse()));
    }

    private function emailHash(Request $request): string
    {
        $email = (string) $request->input('email', $request->input('guest_email', ''));

        return hash('sha256', Str::lower(trim($email)));
    }

    private function rateLimitedResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Too many attempts. Please wait and try again.',
        ], 429);
    }
}
