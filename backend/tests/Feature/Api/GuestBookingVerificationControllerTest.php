<?php

namespace Tests\Feature\Api;

use App\Mail\GuestBookingOtpMail;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GuestBookingVerificationControllerTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_valid_request_stores_a_short_lived_otp_and_sends_it_by_email(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        Mail::fake();

        $this->postJson('/api/guest-booking/request-otp', ['email' => ' Guest@Example.com '])
            ->assertOk()
            ->assertExactJson(['message' => 'Verification code sent.'])
            ->assertJsonMissing(['otp']);

        $otp = $this->sentOtpFor('guest@example.com');
        $record = Cache::get('guest-booking:otp:'.hash('sha256', 'guest@example.com'));

        $this->assertIsArray($record);
        $this->assertNotSame($otp, $record['otp_hash']);
        $this->assertSame(0, $record['attempts']);
        $this->assertSame(now()->addMinutes(5)->getTimestamp(), $record['expires_at']);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $otp);

        /** @var GuestBookingOtpMail $mail */
        $mail = Mail::sent(GuestBookingOtpMail::class)->first();
        $mail->assertSeeInHtml($otp)
            ->assertSeeInHtml('expires in 5 minutes')
            ->assertSeeInHtml('safely ignore this email');
    }

    public function test_invalid_email_is_rejected_without_sending_mail(): void
    {
        Mail::fake();

        $this->postJson('/api/guest-booking/request-otp', ['email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email']);

        Mail::assertNothingSent();
    }

    public function test_correct_otp_returns_a_one_time_email_bound_token(): void
    {
        Mail::fake();
        $this->postJson('/api/guest-booking/request-otp', ['email' => 'guest@example.com'])->assertOk();
        $otp = $this->sentOtpFor('guest@example.com');

        $token = $this->postJson('/api/guest-booking/verify-otp', [
            'email' => 'GUEST@example.com',
            'otp' => $otp,
        ])->assertOk()
            ->assertJsonPath('message', 'Email verified.')
            ->json('verification_token');

        $this->assertIsString($token);
        $this->assertSame(64, strlen($token));
        $this->assertSame(
            'guest@example.com',
            Cache::get('guest-booking:verified:'.hash('sha256', $token)),
        );

        $this->postJson('/api/guest-booking/verify-otp', [
            'email' => 'guest@example.com',
            'otp' => $otp,
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp']);
    }

    public function test_wrong_and_expired_otps_are_rejected_with_the_same_generic_error(): void
    {
        $this->travelTo('2026-09-15 09:00:00');
        Mail::fake();
        $this->postJson('/api/guest-booking/request-otp', ['email' => 'guest@example.com'])->assertOk();

        $this->postJson('/api/guest-booking/verify-otp', [
            'email' => 'guest@example.com',
            'otp' => '000000',
        ])->assertUnprocessable()
            ->assertJsonPath('errors.otp.0', 'The verification code is invalid or has expired.');

        $this->travel(6)->minutes();

        $this->postJson('/api/guest-booking/verify-otp', [
            'email' => 'guest@example.com',
            'otp' => $this->sentOtpFor('guest@example.com'),
        ])->assertUnprocessable()
            ->assertJsonPath('errors.otp.0', 'The verification code is invalid or has expired.');
    }

    public function test_five_wrong_attempts_invalidate_the_otp(): void
    {
        Mail::fake();
        $this->postJson('/api/guest-booking/request-otp', ['email' => 'guest@example.com'])->assertOk();
        $otp = $this->sentOtpFor('guest@example.com');

        foreach (range(1, 5) as $attempt) {
            $this->postJson('/api/guest-booking/verify-otp', [
                'email' => 'guest@example.com',
                'otp' => '000000',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/guest-booking/verify-otp', [
            'email' => 'guest@example.com',
            'otp' => $otp,
        ])->assertUnprocessable()->assertJsonValidationErrors(['otp']);
    }

    public function test_otp_request_is_rate_limited_by_normalized_email(): void
    {
        Mail::fake();

        foreach (['guest@example.com', 'GUEST@example.com', ' guest@example.com '] as $email) {
            $this->postJson('/api/guest-booking/request-otp', ['email' => $email])->assertOk();
        }

        $this->postJson('/api/guest-booking/request-otp', ['email' => 'guest@example.com'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', 'Too many attempts. Please wait and try again.');

        Mail::assertSent(GuestBookingOtpMail::class, 3);
    }

    public function test_otp_verification_is_rate_limited_by_ip(): void
    {
        foreach (range(1, 10) as $attempt) {
            $this->postJson('/api/guest-booking/verify-otp', [
                'email' => 'guest@example.com',
                'otp' => '000000',
            ])->assertUnprocessable();
        }

        $this->postJson('/api/guest-booking/verify-otp', [
            'email' => 'guest@example.com',
            'otp' => '000000',
        ])->assertTooManyRequests();
    }

    private function sentOtpFor(string $email): string
    {
        $otp = null;

        Mail::assertSent(GuestBookingOtpMail::class, function (GuestBookingOtpMail $mail) use ($email, &$otp): bool {
            if (! $mail->hasTo($email)) {
                return false;
            }

            $otp = $mail->otp;

            return true;
        });

        $this->assertIsString($otp);

        return $otp;
    }
}
