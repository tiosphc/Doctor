<?php

namespace App\Services;

use App\Models\Doctor;
use App\Models\User;
use App\Notifications\DoctorAccountInvitation;
use App\Support\PublicImage;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class DoctorAccountService
{
    public function __construct(
        private readonly DoctorLifecycleService $doctorLifecycle,
        private readonly AuditLogger $auditLogger,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): DoctorAccountCreationResult
    {
        $avatarPath = $this->storeAvatar($attributes['avatar'] ?? null);

        try {
            $doctor = DB::transaction(function () use ($attributes, $avatarPath): Doctor {
                $email = Str::lower(trim((string) $attributes['email']));
                $user = User::create([
                    'name' => $attributes['name'],
                    'email' => $email,
                    'phone' => $attributes['phone'] ?? null,
                    'password' => Hash::make(Str::random(64)),
                    'must_change_password' => true,
                    'role' => User::ROLE_DOCTOR,
                    'email_verified_at' => null,
                ]);

                $doctor = Doctor::create([
                    'user_id' => $user->id,
                    'name' => $attributes['name'],
                    'specialty' => $attributes['specialty'],
                    'bio' => $attributes['bio'] ?? null,
                    'phone' => $attributes['phone'] ?? null,
                    'email' => $email,
                    'avatar' => $avatarPath,
                    'status' => Doctor::STATUS_ACTIVE,
                ]);
                $actorName = request()->user()?->name ?? 'Admin';
                $this->auditLogger->log(
                    AuditLogger::ACTION_CREATE,
                    AuditLogger::MODULE_DOCTOR,
                    $doctor,
                    "{$actorName} đã tạo bác sĩ {$doctor->name}.",
                    newValues: $doctor->only(['name', 'specialty', 'phone', 'email', 'status']),
                );

                return $doctor->load('user');
            });
        } catch (Throwable $exception) {
            PublicImage::delete($avatarPath);

            throw $exception;
        }

        return new DoctorAccountCreationResult(
            doctor: $doctor,
            invitationQueued: $this->queueInvitation($doctor),
        );
    }

    /** @param array<string, mixed> $attributes */
    public function update(Doctor $doctor, array $attributes): Doctor
    {
        $avatarPath = $this->storeAvatar($attributes['avatar'] ?? null);
        $previousAvatar = $doctor->avatar;
        $shouldRefreshInvitation = false;

        try {
            $updatedDoctor = DB::transaction(function () use (
                $doctor,
                $attributes,
                $avatarPath,
                &$shouldRefreshInvitation,
            ): Doctor {
                $lockedDoctor = Doctor::query()->whereKey($doctor->id)->lockForUpdate()->firstOrFail();
                $user = $lockedDoctor->user()->lockForUpdate()->first();
                $doctorUpdates = $attributes;
                unset($doctorUpdates['avatar']);

                if ($avatarPath !== null) {
                    $doctorUpdates['avatar'] = $avatarPath;
                }

                if (array_key_exists('email', $doctorUpdates)) {
                    $doctorUpdates['email'] = Str::lower(trim((string) $doctorUpdates['email']));
                }

                if (array_key_exists('status', $doctorUpdates)) {
                    $this->doctorLifecycle->assertStatusTransitionAllowed(
                        $lockedDoctor,
                        $doctorUpdates['status'],
                    );
                }

                $trackedFields = array_values(array_intersect(
                    ['name', 'specialty', 'bio', 'phone', 'email', 'avatar', 'status'],
                    array_keys($doctorUpdates),
                ));
                $before = $lockedDoctor->only($trackedFields);

                if ($user !== null) {
                    $userUpdates = [];

                    foreach (['name', 'email', 'phone'] as $field) {
                        if (array_key_exists($field, $doctorUpdates)) {
                            $userUpdates[$field] = $doctorUpdates[$field];
                        }
                    }

                    if (array_key_exists('email', $userUpdates) && $userUpdates['email'] !== $user->email) {
                        $userUpdates['email_verified_at'] = null;
                        $shouldRefreshInvitation = $user->must_change_password;
                    }

                    if ($userUpdates !== []) {
                        $user->update($userUpdates);
                    }
                }

                $lockedDoctor->update($doctorUpdates);
                $lockedDoctor->refresh();
                $diff = $this->auditLogger->diff($before, $lockedDoctor->only($trackedFields));

                if ($diff['old'] !== []) {
                    $action = match ($lockedDoctor->status) {
                        Doctor::STATUS_ACTIVE => array_key_exists('status', $diff['new'])
                            ? AuditLogger::ACTION_ACTIVATE
                            : AuditLogger::ACTION_UPDATE,
                        Doctor::STATUS_INACTIVE => array_key_exists('status', $diff['new'])
                            ? AuditLogger::ACTION_DEACTIVATE
                            : AuditLogger::ACTION_UPDATE,
                        default => AuditLogger::ACTION_UPDATE,
                    };
                    $actorName = request()->user()?->name ?? 'Admin';
                    $verb = match ($action) {
                        AuditLogger::ACTION_ACTIVATE => 'kích hoạt lại',
                        AuditLogger::ACTION_DEACTIVATE => 'ngừng hoạt động',
                        default => 'cập nhật',
                    };
                    $this->auditLogger->log(
                        $action,
                        AuditLogger::MODULE_DOCTOR,
                        $lockedDoctor,
                        "{$actorName} đã {$verb} bác sĩ {$lockedDoctor->name}.",
                        oldValues: $diff['old'],
                        newValues: $diff['new'],
                    );
                }

                return $lockedDoctor
                    ->load(['user', 'services.category:id,name,slug'])
                    ->loadCount(['services', 'schedules']);
            });
        } catch (Throwable $exception) {
            PublicImage::delete($avatarPath);

            throw $exception;
        }

        if ($avatarPath !== null && $previousAvatar !== $avatarPath) {
            PublicImage::delete($previousAvatar);
        }

        if ($shouldRefreshInvitation) {
            $this->queueInvitation($updatedDoctor);
        }

        return $updatedDoctor;
    }

    public function queueInvitation(Doctor $doctor): bool
    {
        $doctor->loadMissing('user');
        $user = $doctor->user;

        if ($user === null
            || ! $user->isDoctor()
            || ! $user->must_change_password
            || $doctor->status !== Doctor::STATUS_ACTIVE) {
            return false;
        }

        try {
            Password::broker()->deleteToken($user);
            $token = Password::broker()->createToken($user);
            $user->notify(new DoctorAccountInvitation($token));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /** @param array{email: string, token: string, password: string, password_confirmation: string} $credentials */
    public function setupPassword(array $credentials): string
    {
        return Password::broker()->reset([
            ...$credentials,
            'role' => User::ROLE_DOCTOR,
            'must_change_password' => true,
        ], function (User $user, string $password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'must_change_password' => false,
                'email_verified_at' => now(),
                'remember_token' => Str::random(60),
            ])->save();

            event(new PasswordReset($user));
        });
    }

    private function storeAvatar(mixed $avatar): ?string
    {
        return $avatar instanceof UploadedFile
            ? PublicImage::store($avatar, 'doctors')
            : null;
    }
}
