<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StaffAccountService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array{name: string, email: string, phone?: ?string, password: string} $attributes */
    public function create(array $attributes): User
    {
        try {
            return DB::transaction(function () use ($attributes): User {
                $staff = User::create([
                    ...$attributes,
                    'role' => User::ROLE_RECEPTIONIST,
                ]);
                $actorName = request()->user()?->name ?? 'Admin';
                $this->auditLogger->log(
                    AuditLogger::ACTION_CREATE,
                    AuditLogger::MODULE_STAFF,
                    $staff,
                    "{$actorName} đã tạo nhân viên {$staff->name}.",
                    newValues: $staff->only(['name', 'email', 'phone', 'role']),
                );

                return $staff->load('doctorProfile');
            }, 3);
        } catch (QueryException $exception) {
            $this->throwEmailConflictOrRethrow($exception);
        }
    }

    /** @param array{name?: string, email?: string, phone?: ?string, password?: ?string} $attributes */
    public function update(User $staff, array $attributes): User
    {
        try {
            return DB::transaction(function () use ($staff, $attributes): User {
                $lockedStaff = $this->lockReceptionist($staff);

                if (empty($attributes['password'])) {
                    unset($attributes['password']);
                }

                $passwordChanged = array_key_exists('password', $attributes);
                $trackedFields = array_values(array_intersect(
                    ['name', 'email', 'phone', 'role'],
                    array_keys($attributes),
                ));
                $before = $lockedStaff->only($trackedFields);
                $lockedStaff->update($attributes);
                $lockedStaff->refresh();
                $diff = $this->auditLogger->diff($before, $lockedStaff->only($trackedFields));

                if ($diff['old'] !== [] || $passwordChanged) {
                    $actorName = request()->user()?->name ?? 'Admin';
                    $description = match (true) {
                        $passwordChanged && $diff['old'] === [] => "{$actorName} đã thay đổi mật khẩu nhân viên {$lockedStaff->name}.",
                        $passwordChanged => "{$actorName} đã cập nhật thông tin và thay đổi mật khẩu nhân viên {$lockedStaff->name}.",
                        default => "{$actorName} đã cập nhật nhân viên {$lockedStaff->name}.",
                    };
                    $this->auditLogger->log(
                        AuditLogger::ACTION_UPDATE,
                        AuditLogger::MODULE_STAFF,
                        $lockedStaff,
                        $description,
                        oldValues: $diff['old'],
                        newValues: $diff['new'],
                        metadata: $passwordChanged ? ['password_changed' => true] : [],
                    );
                }

                return $lockedStaff->load('doctorProfile');
            }, 3);
        } catch (QueryException $exception) {
            $this->throwEmailConflictOrRethrow($exception);
        }
    }

    public function delete(User $staff): void
    {
        DB::transaction(function () use ($staff): void {
            $lockedStaff = $this->lockReceptionist($staff);

            if ($lockedStaff->appointments()->exists()
                || $lockedStaff->blogs()->exists()
                || $lockedStaff->reviews()->exists()
                || $lockedStaff->vouchers()->exists()
                || $lockedStaff->doctorProfile()->exists()) {
                throw new BusinessConflictException(
                    'Không thể xóa nhân viên vì tài khoản đã có dữ liệu nghiệp vụ liên quan.',
                );
            }

            $lockedStaff->tokens()->delete();
            $lockedStaff->notifications()->delete();
            DB::table('sessions')->where('user_id', $lockedStaff->id)->delete();
            DB::table('password_reset_tokens')->where('email', $lockedStaff->email)->delete();
            $actorName = request()->user()?->name ?? 'Admin';
            $this->auditLogger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::MODULE_STAFF,
                $lockedStaff,
                "{$actorName} đã xóa nhân viên {$lockedStaff->name}.",
                oldValues: $lockedStaff->only(['name', 'email', 'phone', 'role']),
            );
            $lockedStaff->delete();
        }, 3);
    }

    private function lockReceptionist(User $staff): User
    {
        return User::query()
            ->whereKey($staff->id)
            ->where('role', User::ROLE_RECEPTIONIST)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function throwEmailConflictOrRethrow(QueryException $exception): never
    {
        if ($exception->getCode() === '23000'
            && str_contains($exception->getMessage(), 'users_email_unique')) {
            throw ValidationException::withMessages([
                'email' => 'Email này đã được sử dụng.',
            ]);
        }

        throw $exception;
    }
}
