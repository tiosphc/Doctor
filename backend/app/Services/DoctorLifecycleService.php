<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;

class DoctorLifecycleService
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function assertStatusTransitionAllowed(Doctor $doctor, string $nextStatus): void
    {
        if ($doctor->status === $nextStatus) {
            return;
        }

        if ($nextStatus === Doctor::STATUS_INACTIVE) {
            $conflictingAppointmentIds = $doctor->appointments()
                ->where(function (Builder $query): void {
                    $query->whereIn('status', [
                        Appointment::STATUS_CHECKED_IN,
                        Appointment::STATUS_IN_PROGRESS,
                        Appointment::STATUS_TREATMENT_DONE,
                    ])->orWhere(function (Builder $future): void {
                        $future->whereIn('status', [
                            Appointment::STATUS_PENDING,
                            Appointment::STATUS_CONFIRMED,
                        ])->where(function (Builder $scheduled): void {
                            $scheduled->whereDate('appointment_date', '>', now()->toDateString())
                                ->orWhere(function (Builder $sameDay): void {
                                    $sameDay->whereDate('appointment_date', now()->toDateString())
                                        ->where('start_time', '>=', now()->format('H:i:s'));
                                });
                        });
                    });
                })
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            if ($conflictingAppointmentIds !== []) {
                throw new BusinessConflictException(
                    'Không thể ngừng hoạt động bác sĩ vì vẫn còn lịch hẹn đang hoạt động hoặc lịch hẹn tương lai. Vui lòng xử lý, đổi lịch hoặc hủy các lịch hẹn phù hợp trước.',
                    ['appointment_ids' => $conflictingAppointmentIds],
                );
            }

            return;
        }

        if (! $doctor->services()->exists() || ! $doctor->schedules()->exists()) {
            throw new BusinessConflictException(
                'Không thể kích hoạt lại bác sĩ khi chưa có dịch vụ phụ trách và lịch làm việc.',
            );
        }
    }

    public function permanentlyDelete(Doctor $doctor): ?string
    {
        return DB::transaction(function () use ($doctor): ?string {
            $lockedDoctor = Doctor::query()->whereKey($doctor->id)->lockForUpdate()->firstOrFail();
            $linkedUser = $lockedDoctor->user()->lockForUpdate()->first();

            if ($lockedDoctor->appointments()->exists() || $lockedDoctor->reviews()->exists()) {
                $this->throwHistoricalDataConflict();
            }

            if ($linkedUser !== null) {
                $this->assertLinkedUserCanBeDeleted($linkedUser, $lockedDoctor);
            }

            $avatar = $lockedDoctor->avatar;
            $actorName = request()->user()?->name ?? 'Admin';
            $this->auditLogger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::MODULE_DOCTOR,
                $lockedDoctor,
                "{$actorName} đã xóa bác sĩ {$lockedDoctor->name}.",
                oldValues: $lockedDoctor->only(['name', 'specialty', 'phone', 'email', 'status']),
            );
            $lockedDoctor->delete();

            if ($linkedUser !== null) {
                Password::broker()->deleteToken($linkedUser);
                $linkedUser->tokens()->delete();
                DB::table('sessions')->where('user_id', $linkedUser->id)->delete();
                $linkedUser->delete();
            }

            return $avatar;
        }, 3);
    }

    private function assertLinkedUserCanBeDeleted(User $user, Doctor $doctor): void
    {
        if (! $user->isDoctor()
            || $user->doctorProfile()->whereKeyNot($doctor->id)->exists()
            || $user->appointments()->exists()
            || $user->reviews()->exists()
            || $user->vouchers()->exists()
            || $user->blogs()->exists()
            || $user->notifications()->exists()) {
            $this->throwHistoricalDataConflict();
        }
    }

    private function throwHistoricalDataConflict(): never
    {
        throw new BusinessConflictException(
            "Không thể xóa vĩnh viễn bác sĩ này vì đã có dữ liệu lịch sử. Hãy sử dụng chức năng 'Ngừng hoạt động' thay thế.",
        );
    }
}
