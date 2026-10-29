<?php

namespace App\Services;

use App\Exceptions\BusinessConflictException;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Models\DoctorTimeOff;
use App\Models\Service;
use App\Models\User;
use App\Notifications\ReviewInvitationNotification;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class BookingService
{
    public function __construct(
        private readonly VoucherService $voucherService,
        private readonly LoyaltyService $loyaltyService,
        private readonly AuditLogger $auditLogger,
        private readonly RegisteredCustomerService $registeredCustomerService,
    ) {}

    /** @return list<string> */
    public function availableSlots(
        Doctor $doctor,
        Service $service,
        CarbonImmutable $date,
        bool $lockRows = false,
        ?int $excludedAppointmentId = null,
    ): array {
        $this->assertDateIsNotPast($date);
        $this->assertDoctorCanPerformService($doctor, $service);

        $scheduleQuery = $doctor->schedules()
            ->where('day_of_week', $date->isoWeekday())
            ->orderBy('start_time')
            ->orderBy('id');
        $timeOffQuery = $doctor->timeOffs()
            ->whereDate('date', $date->toDateString())
            ->orderBy('start_time')
            ->orderBy('id');
        $appointmentQuery = $this->blockingAppointmentsQuery($doctor, $date, $excludedAppointmentId);

        if ($lockRows) {
            $scheduleQuery->lockForUpdate();
            $timeOffQuery->lockForUpdate();
            $appointmentQuery->lockForUpdate();
        }

        $schedules = $scheduleQuery->get();
        $timeOffs = $timeOffQuery->get();

        if ($timeOffs->contains(fn (DoctorTimeOff $timeOff): bool => $timeOff->start_time === null)) {
            return [];
        }

        $blockedPeriods = [
            ...$timeOffs->map(fn (DoctorTimeOff $timeOff): array => [
                $date->setTimeFromTimeString($timeOff->start_time),
                $date->setTimeFromTimeString($timeOff->end_time),
            ])->all(),
            ...$appointmentQuery->get()->map(fn (Appointment $appointment): array => [
                $date->setTimeFromTimeString($appointment->start_time),
                $date->setTimeFromTimeString($appointment->end_time),
            ])->all(),
        ];

        $slotInterval = max(1, (int) config('booking.slot_interval_minutes'));
        $duration = $service->duration;
        $now = CarbonImmutable::now();
        $slots = [];

        foreach ($schedules as $schedule) {
            $periodStart = $date->setTimeFromTimeString($schedule->start_time);
            $periodEnd = $date->setTimeFromTimeString($schedule->end_time);

            for ($candidate = $periodStart; $candidate->addMinutes($duration)->lte($periodEnd); $candidate = $candidate->addMinutes($slotInterval)) {
                if ($candidate->lt($now)) {
                    continue;
                }

                $candidateEnd = $candidate->addMinutes($duration);
                $isBlocked = collect($blockedPeriods)->contains(
                    fn (array $period): bool => $this->intervalsOverlap($candidate, $candidateEnd, $period[0], $period[1]),
                );

                if (! $isBlocked) {
                    $slots[$candidate->format('H:i')] = true;
                }
            }
        }

        $availableSlots = array_keys($slots);
        sort($availableSlots);

        return $availableSlots;
    }

    public function createAppointment(
        User $customer,
        int $doctorId,
        int $serviceId,
        CarbonImmutable $date,
        string $startTime,
        ?string $note,
        ?int $voucherId = null,
    ): Appointment {
        $customerProfile = $this->registeredCustomerService->ensureForUser($customer)->customer;

        return $this->createBookingWithUniqueCode(
            $customer,
            $customerProfile,
            null,
            $doctorId,
            $serviceId,
            $date,
            $startTime,
            $note,
            $voucherId,
        );
    }

    /** @return list<string> */
    public function availableRescheduleSlots(
        Appointment $appointment,
        User $customer,
        CarbonImmutable $date,
    ): array {
        if ($appointment->user_id !== $customer->id) {
            throw new AuthorizationException;
        }

        $this->assertAppointmentCanBeRescheduled($appointment);
        $doctor = Doctor::query()->findOrFail($appointment->doctor_id);
        $service = Service::query()->findOrFail($appointment->service_id);

        return $this->availableSlots($doctor, $service, $date, false, $appointment->id);
    }

    /** @return list<string> */
    public function availablePublicRescheduleSlots(Appointment $appointment, CarbonImmutable $date): array
    {
        $this->assertAppointmentCanBeRescheduled($appointment);
        $doctor = Doctor::query()->findOrFail($appointment->doctor_id);
        $service = Service::query()->findOrFail($appointment->service_id);

        return $this->availableSlots($doctor, $service, $date, false, $appointment->id);
    }

    /** @param array{name: string, email: string, phone: string} $guest */
    public function createGuestAppointment(
        array $guest,
        int $doctorId,
        int $serviceId,
        CarbonImmutable $date,
        string $startTime,
        ?string $note,
    ): Appointment {
        $guest['email'] = Str::lower(trim($guest['email']));
        $lockName = 'guest-booking:email-lock:'.hash('sha256', $guest['email']);

        try {
            return Cache::lock($lockName, 10)->block(3, function () use ($guest, $doctorId, $serviceId, $date, $startTime, $note): Appointment {
                return $this->createBookingWithUniqueCode(
                    null,
                    null,
                    $guest,
                    $doctorId,
                    $serviceId,
                    $date,
                    $startTime,
                    $note,
                );
            });
        } catch (LockTimeoutException) {
            throw new BusinessConflictException('Another booking for this email is being processed. Please try again.');
        }
    }

    /**
     * @param  array{name: string, email: string, phone: string}|null  $guest
     */
    private function createBookingWithUniqueCode(
        ?User $customer,
        ?Customer $customerProfile,
        ?array $guest,
        int $doctorId,
        int $serviceId,
        CarbonImmutable $date,
        string $startTime,
        ?string $note,
        ?int $voucherId = null,
    ): Appointment {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            try {
                return $this->createBooking(
                    $customer,
                    $customerProfile,
                    $guest,
                    $doctorId,
                    $serviceId,
                    $date,
                    $startTime,
                    $note,
                    $this->generateBookingCode(),
                    $voucherId,
                );
            } catch (QueryException $exception) {
                if (! $this->isBookingCodeCollision($exception)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to generate a unique booking code.');
    }

    /**
     * @param  array{name: string, email: string, phone: string}|null  $guest
     */
    private function createBooking(
        ?User $customer,
        ?Customer $customerProfile,
        ?array $guest,
        int $doctorId,
        int $serviceId,
        CarbonImmutable $date,
        string $startTime,
        ?string $note,
        string $bookingCode,
        ?int $voucherId = null,
    ): Appointment {
        return DB::transaction(function () use ($customer, $customerProfile, $guest, $doctorId, $serviceId, $date, $startTime, $note, $bookingCode, $voucherId): Appointment {
            $doctor = Doctor::query()->whereKey($doctorId)->lockForUpdate()->firstOrFail();
            $service = Service::query()->whereKey($serviceId)->sharedLock()->firstOrFail();
            $voucher = $voucherId === null || $customer === null
                ? null
                : $this->voucherService->lockForRedemption($voucherId, $customer);
            $normalizedStart = CarbonImmutable::createFromFormat('H:i', $startTime)->format('H:i');

            if ($guest !== null) {
                $this->assertGuestIsBelowActiveAppointmentLimit($guest['email']);
            }

            if (! in_array($normalizedStart, $this->availableSlots($doctor, $service, $date, true), true)) {
                throw new BusinessConflictException('The selected appointment slot is no longer available.');
            }

            $endTime = $date
                ->setTimeFromTimeString($normalizedStart)
                ->addMinutes($service->duration)
                ->format('H:i:s');

            $originalPrice = round((float) $service->price, 2);
            $discountAmount = $voucher === null ? 0.0 : round($originalPrice * (float) $voucher->value / 100, 2);
            $appointment = new Appointment;
            $appointment->forceFill([
                'user_id' => $customer?->id,
                'customer_id' => $customerProfile?->id,
                'customer_name_snapshot' => $customerProfile?->name,
                'customer_email_snapshot' => $customerProfile?->primary_email,
                'customer_phone_snapshot' => $customerProfile?->primary_phone,
                'guest_name' => $guest['name'] ?? null,
                'guest_email' => $guest['email'] ?? null,
                'guest_phone' => $guest['phone'] ?? null,
                'booking_code' => $bookingCode,
                'doctor_id' => $doctor->id,
                'service_id' => $service->id,
                'voucher_id' => $voucher?->id,
                'original_price' => $originalPrice,
                'discount_amount' => $discountAmount,
                'final_price' => max(0, $originalPrice - $discountAmount),
                'appointment_date' => $date->toDateString(),
                'start_time' => $normalizedStart.':00',
                'end_time' => $endTime,
                'status' => Appointment::STATUS_PENDING,
                'note' => $note,
            ])->save();

            if ($voucher !== null) {
                $this->voucherService->consume($voucher);
            }

            $actorName = request()->user()?->name ?? ($guest['name'] ?? 'Khách');
            $targetName = $this->appointmentTargetName($appointment);
            $this->auditLogger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::MODULE_APPOINTMENT,
                $appointment,
                "{$actorName} đã tạo lịch hẹn {$targetName}.",
                newValues: $appointment->only([
                    'booking_code', 'doctor_id', 'service_id', 'appointment_date',
                    'start_time', 'end_time', 'status',
                ]),
                targetName: $targetName,
                actorName: $guest['name'] ?? null,
                actorRole: $guest !== null ? 'guest' : null,
            );

            return $appointment->load(['doctor', 'service.category:id,name,slug', 'voucher']);
        }, 3);
    }

    /** @param list<int> $serviceIds */
    public function syncDoctorServices(Doctor $doctor, array $serviceIds): Doctor
    {
        return DB::transaction(function () use ($doctor, $serviceIds): Doctor {
            $lockedDoctor = $this->lockDoctor($doctor);
            $currentServiceIds = $lockedDoctor->services()->lockForUpdate()->pluck('services.id')->all();
            sort($currentServiceIds);
            sort($serviceIds);
            $removedServiceIds = array_values(array_diff($currentServiceIds, $serviceIds));

            if ($removedServiceIds !== []) {
                $conflictingAppointmentIds = $this->futureBlockingAppointmentsQuery($lockedDoctor)
                    ->whereIn('service_id', $removedServiceIds)
                    ->lockForUpdate()
                    ->pluck('id')
                    ->all();

                if ($conflictingAppointmentIds !== []) {
                    throw new BusinessConflictException(
                        'Không thể bỏ dịch vụ này vì bác sĩ đang có lịch hẹn tương lai sử dụng dịch vụ đó. Vui lòng xử lý hoặc đổi lịch hẹn trước.',
                        ['appointment_ids' => $conflictingAppointmentIds],
                    );
                }
            }

            $lockedDoctor->services()->sync($serviceIds);
            if ($currentServiceIds !== $serviceIds) {
                $actorName = request()->user()?->name ?? 'Admin';
                $this->auditLogger->log(
                    AuditLogger::ACTION_UPDATE,
                    AuditLogger::MODULE_DOCTOR,
                    $lockedDoctor,
                    "{$actorName} đã cập nhật dịch vụ phụ trách của bác sĩ {$lockedDoctor->name}.",
                    oldValues: ['service_ids' => $currentServiceIds],
                    newValues: ['service_ids' => $serviceIds],
                );
            }

            return $lockedDoctor
                ->load(['user', 'services.category:id,name,slug'])
                ->loadCount(['services', 'schedules']);
        }, 3);
    }

    /**
     * @param  list<array{id?: int|null, day_of_week: int, start_time: string, end_time: string}>  $schedules
     * @return Collection<int, DoctorSchedule>
     */
    public function replaceSchedules(Doctor $doctor, array $schedules): Collection
    {
        return DB::transaction(function () use ($doctor, $schedules): Collection {
            $lockedDoctor = $this->lockDoctor($doctor);
            $existingSchedules = $lockedDoctor->schedules()->lockForUpdate()->get()->keyBy('id');
            $this->assertSchedulePayloadDoesNotOverlap($schedules);

            $retainedScheduleIds = collect($schedules)->pluck('id')->filter()->map(fn (mixed $id): int => (int) $id);
            if ($retainedScheduleIds->diff($existingSchedules->keys())->isNotEmpty()) {
                throw new BusinessConflictException('The working schedule changed while it was being edited.');
            }

            $this->assertScheduleReplacementKeepsFutureAppointmentsValid($lockedDoctor, $schedules);
            $removedScheduleIds = $existingSchedules->keys()->diff($retainedScheduleIds);

            foreach ($schedules as $scheduleData) {
                $scheduleId = $scheduleData['id'] ?? null;
                unset($scheduleData['id']);

                if ($scheduleId === null) {
                    $lockedDoctor->schedules()->create($scheduleData);
                } else {
                    $existingSchedules->get($scheduleId)->update($scheduleData);
                }
            }

            if ($removedScheduleIds->isNotEmpty()) {
                $lockedDoctor->schedules()->whereKey($removedScheduleIds)->delete();
            }

            $updatedSchedules = $lockedDoctor->schedules()
                ->orderBy('day_of_week')
                ->orderBy('start_time')
                ->orderBy('id')
                ->get();
            $before = $existingSchedules->values()->map->only(['id', 'day_of_week', 'start_time', 'end_time'])->all();
            $after = $updatedSchedules->map->only(['id', 'day_of_week', 'start_time', 'end_time'])->all();
            if ($before !== $after) {
                $actorName = request()->user()?->name ?? 'Admin';
                $this->auditLogger->log(
                    AuditLogger::ACTION_UPDATE,
                    AuditLogger::MODULE_DOCTOR,
                    $lockedDoctor,
                    "{$actorName} đã cập nhật lịch làm việc của bác sĩ {$lockedDoctor->name}.",
                    oldValues: ['schedules' => $before],
                    newValues: ['schedules' => $after],
                );
            }

            return $updatedSchedules;
        }, 3);
    }

    /** @param array{day_of_week: int, start_time: string, end_time: string} $data */
    public function createSchedule(Doctor $doctor, array $data): DoctorSchedule
    {
        return DB::transaction(function () use ($doctor, $data): DoctorSchedule {
            $lockedDoctor = $this->lockDoctor($doctor);
            $this->assertScheduleDoesNotOverlap($lockedDoctor, $data);

            $schedule = $lockedDoctor->schedules()->create($data);
            $actorName = request()->user()?->name ?? 'Admin';
            $this->auditLogger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::MODULE_DOCTOR,
                $schedule,
                "{$actorName} đã thêm lịch làm việc cho bác sĩ {$lockedDoctor->name}.",
                newValues: $schedule->only(['day_of_week', 'start_time', 'end_time']),
                targetName: $lockedDoctor->name.' - lịch làm việc',
            );

            return $schedule;
        }, 3);
    }

    /** @param array{day_of_week: int, start_time: string, end_time: string} $data */
    public function updateSchedule(Doctor $doctor, DoctorSchedule $schedule, array $data): DoctorSchedule
    {
        return DB::transaction(function () use ($doctor, $schedule, $data): DoctorSchedule {
            $lockedDoctor = $this->lockDoctor($doctor);
            $lockedSchedule = $lockedDoctor->schedules()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $this->assertScheduleDoesNotOverlap($lockedDoctor, $data, $lockedSchedule);
            $this->assertScheduleChangeKeepsFutureAppointmentsValid($lockedDoctor, $lockedSchedule, $data);
            $before = $lockedSchedule->only(['day_of_week', 'start_time', 'end_time']);
            $lockedSchedule->update($data);
            $diff = $this->auditLogger->diff($before, $lockedSchedule->only(['day_of_week', 'start_time', 'end_time']));
            if ($diff['old'] !== []) {
                $actorName = request()->user()?->name ?? 'Admin';
                $this->auditLogger->log(
                    AuditLogger::ACTION_UPDATE,
                    AuditLogger::MODULE_DOCTOR,
                    $lockedSchedule,
                    "{$actorName} đã cập nhật lịch làm việc của bác sĩ {$lockedDoctor->name}.",
                    oldValues: $diff['old'],
                    newValues: $diff['new'],
                    targetName: $lockedDoctor->name.' - lịch làm việc',
                );
            }

            return $lockedSchedule->refresh();
        }, 3);
    }

    public function deleteSchedule(Doctor $doctor, DoctorSchedule $schedule): void
    {
        DB::transaction(function () use ($doctor, $schedule): void {
            $lockedDoctor = $this->lockDoctor($doctor);
            $lockedSchedule = $lockedDoctor->schedules()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();
            $this->assertScheduleChangeKeepsFutureAppointmentsValid($lockedDoctor, $lockedSchedule);
            $actorName = request()->user()?->name ?? 'Admin';
            $this->auditLogger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::MODULE_DOCTOR,
                $lockedSchedule,
                "{$actorName} đã xóa lịch làm việc của bác sĩ {$lockedDoctor->name}.",
                oldValues: $lockedSchedule->only(['day_of_week', 'start_time', 'end_time']),
                targetName: $lockedDoctor->name.' - lịch làm việc',
            );
            $lockedSchedule->delete();
        }, 3);
    }

    /** @param array{date: string, start_time?: ?string, end_time?: ?string, reason?: ?string} $data */
    public function createTimeOff(Doctor $doctor, array $data): DoctorTimeOff
    {
        return DB::transaction(function () use ($doctor, $data): DoctorTimeOff {
            $lockedDoctor = $this->lockDoctor($doctor);
            $this->assertTimeOffDoesNotConflictWithFutureAppointments($lockedDoctor, $data);

            $timeOff = $lockedDoctor->timeOffs()->create($data);
            $actorName = request()->user()?->name ?? 'Admin';
            $this->auditLogger->log(
                AuditLogger::ACTION_CREATE,
                AuditLogger::MODULE_DOCTOR,
                $timeOff,
                "{$actorName} đã thêm ngày nghỉ cho bác sĩ {$lockedDoctor->name}.",
                newValues: $timeOff->only(['date', 'start_time', 'end_time', 'reason']),
                targetName: $lockedDoctor->name.' - ngày nghỉ '.$timeOff->date->toDateString(),
            );

            return $timeOff;
        }, 3);
    }

    /** @param array{date: string, start_time?: ?string, end_time?: ?string, reason?: ?string} $data */
    public function updateTimeOff(Doctor $doctor, DoctorTimeOff $timeOff, array $data): DoctorTimeOff
    {
        return DB::transaction(function () use ($doctor, $timeOff, $data): DoctorTimeOff {
            $lockedDoctor = $this->lockDoctor($doctor);
            $lockedTimeOff = $lockedDoctor->timeOffs()->whereKey($timeOff->id)->lockForUpdate()->firstOrFail();
            $this->assertTimeOffDoesNotConflictWithFutureAppointments($lockedDoctor, $data);
            $before = $lockedTimeOff->only(['date', 'start_time', 'end_time', 'reason']);
            $lockedTimeOff->update($data);
            $diff = $this->auditLogger->diff($before, $lockedTimeOff->only(['date', 'start_time', 'end_time', 'reason']));
            if ($diff['old'] !== []) {
                $actorName = request()->user()?->name ?? 'Admin';
                $this->auditLogger->log(
                    AuditLogger::ACTION_UPDATE,
                    AuditLogger::MODULE_DOCTOR,
                    $lockedTimeOff,
                    "{$actorName} đã cập nhật ngày nghỉ của bác sĩ {$lockedDoctor->name}.",
                    oldValues: $diff['old'],
                    newValues: $diff['new'],
                    targetName: $lockedDoctor->name.' - ngày nghỉ',
                );
            }

            return $lockedTimeOff->refresh();
        }, 3);
    }

    public function deleteTimeOff(Doctor $doctor, DoctorTimeOff $timeOff): void
    {
        DB::transaction(function () use ($doctor, $timeOff): void {
            $lockedDoctor = $this->lockDoctor($doctor);
            $lockedTimeOff = $lockedDoctor->timeOffs()->whereKey($timeOff->id)->lockForUpdate()->firstOrFail();
            $actorName = request()->user()?->name ?? 'Admin';
            $this->auditLogger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::MODULE_DOCTOR,
                $lockedTimeOff,
                "{$actorName} đã xóa ngày nghỉ của bác sĩ {$lockedDoctor->name}.",
                oldValues: $lockedTimeOff->only(['date', 'start_time', 'end_time', 'reason']),
                targetName: $lockedDoctor->name.' - ngày nghỉ',
            );
            $lockedTimeOff->delete();
        }, 3);
    }

    public function cancelAppointment(Appointment $appointment, User $customer): Appointment
    {
        return DB::transaction(function () use ($appointment, $customer): Appointment {
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($lockedAppointment->user_id !== $customer->id) {
                throw new AuthorizationException;
            }

            $previousStatus = $lockedAppointment->status;
            $cancelled = $this->cancelLockedAppointment($lockedAppointment);
            $this->logAppointmentTransition($cancelled, $previousStatus, AuditLogger::ACTION_CANCEL);

            return $cancelled;
        }, 3);
    }

    public function rescheduleAppointment(
        Appointment $appointment,
        User $customer,
        CarbonImmutable $date,
        string $startTime,
    ): AppointmentRescheduleResult {
        return $this->rescheduleAppointmentForActor($appointment, $date, $startTime, $customer);
    }

    public function reschedulePublicAppointment(
        Appointment $appointment,
        CarbonImmutable $date,
        string $startTime,
        string $phone,
    ): AppointmentRescheduleResult {
        return $this->rescheduleAppointmentForActor(
            $appointment,
            $date,
            $startTime,
            publicPhone: $phone,
            actorRole: 'guest',
        );
    }

    private function rescheduleAppointmentForActor(
        Appointment $appointment,
        CarbonImmutable $date,
        string $startTime,
        ?User $customer = null,
        ?string $actorName = null,
        ?string $actorRole = null,
        ?string $publicPhone = null,
    ): AppointmentRescheduleResult {
        return DB::transaction(function () use ($appointment, $customer, $date, $startTime, $actorName, $actorRole, $publicPhone): AppointmentRescheduleResult {
            $doctor = Doctor::query()->whereKey($appointment->doctor_id)->lockForUpdate()->firstOrFail();
            $service = Service::query()->whereKey($appointment->service_id)->sharedLock()->firstOrFail();
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if ($customer !== null && $lockedAppointment->user_id !== $customer->id) {
                throw new AuthorizationException;
            }

            if ($publicPhone !== null) {
                $this->assertPublicPhoneMatches($lockedAppointment, $publicPhone);
                $actorName = $lockedAppointment->isGuest()
                    ? $lockedAppointment->guest_name
                    : $lockedAppointment->user()->value('name');
            }

            if ($lockedAppointment->doctor_id !== $doctor->id || $lockedAppointment->service_id !== $service->id) {
                throw new BusinessConflictException('The appointment changed while it was being rescheduled.');
            }

            $this->assertAppointmentCanBeRescheduled($lockedAppointment);
            $normalizedStart = CarbonImmutable::createFromFormat('H:i', $startTime)->format('H:i');

            if (! in_array(
                $normalizedStart,
                $this->availableSlots($doctor, $service, $date, true, $lockedAppointment->id),
                true,
            )) {
                throw new BusinessConflictException('The selected appointment slot is no longer available.');
            }

            $previousSchedule = [
                'appointment_date' => $lockedAppointment->appointment_date->toDateString(),
                'start_time' => substr($lockedAppointment->start_time, 0, 5),
                'end_time' => substr($lockedAppointment->end_time, 0, 5),
            ];

            if ($date->toDateString() === $previousSchedule['appointment_date']
                && $normalizedStart === $previousSchedule['start_time']) {
                throw new BusinessConflictException('Bạn chưa thay đổi thời gian lịch hẹn.');
            }

            $endTime = $date
                ->setTimeFromTimeString($normalizedStart)
                ->addMinutes($service->duration)
                ->format('H:i:s');

            $lockedAppointment->update([
                'appointment_date' => $date->toDateString(),
                'start_time' => $normalizedStart.':00',
                'end_time' => $endTime,
                'original_appointment_date' => $lockedAppointment->original_appointment_date
                    ?? $previousSchedule['appointment_date'],
                'original_start_time' => $lockedAppointment->original_start_time
                    ?? $previousSchedule['start_time'].':00',
                'original_end_time' => $lockedAppointment->original_end_time
                    ?? $previousSchedule['end_time'].':00',
                'rescheduled_at' => now(),
                'reschedule_count' => ($lockedAppointment->reschedule_count ?? 0) + 1,
                'reminder_sent_at' => null,
            ]);

            $updatedAppointment = $lockedAppointment->refresh()->load(['user', 'doctor', 'service.category:id,name,slug']);
            $resolvedActorName = request()->user()?->name ?? $actorName ?? 'Khách hàng';
            $targetName = $this->appointmentTargetName($updatedAppointment);
            $this->auditLogger->log(
                AuditLogger::ACTION_RESCHEDULE,
                AuditLogger::MODULE_APPOINTMENT,
                $updatedAppointment,
                "{$resolvedActorName} đã chuyển lịch hẹn {$targetName}.",
                oldValues: $previousSchedule,
                newValues: [
                    'appointment_date' => $updatedAppointment->appointment_date->toDateString(),
                    'start_time' => substr($updatedAppointment->start_time, 0, 5),
                    'end_time' => substr($updatedAppointment->end_time, 0, 5),
                ],
                targetName: $targetName,
                actorName: $actorName,
                actorRole: $actorRole,
            );

            return new AppointmentRescheduleResult(
                $updatedAppointment,
                $previousSchedule,
            );
        }, 3);
    }

    public function cancelPublicAppointment(Appointment $appointment, string $phone): Appointment
    {
        return DB::transaction(function () use ($appointment, $phone): Appointment {
            $lockedAppointment = Appointment::query()
                ->whereKey($appointment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertPublicPhoneMatches($lockedAppointment, $phone);

            $previousStatus = $lockedAppointment->status;
            $cancelled = $this->cancelLockedAppointment($lockedAppointment);
            $this->logAppointmentTransition(
                $cancelled,
                $previousStatus,
                AuditLogger::ACTION_CANCEL,
                $cancelled->isGuest() ? $cancelled->guest_name : $cancelled->user?->name,
                'guest',
            );

            return $cancelled;
        }, 3);
    }

    private function assertPublicPhoneMatches(Appointment $appointment, string $phone): void
    {
        $appointmentPhone = $appointment->isGuest()
            ? $appointment->guest_phone
            : $appointment->user()->value('phone');

        if ($appointmentPhone === null || ! hash_equals(
            PhoneNumber::normalize($appointmentPhone),
            PhoneNumber::normalize($phone),
        )) {
            throw (new ModelNotFoundException)->setModel(Appointment::class);
        }
    }

    public function transitionAppointment(Appointment $appointment, string $status, ?User $actor = null): Appointment
    {
        return DB::transaction(function () use ($appointment, $status, $actor): Appointment {
            $lockedAppointment = Appointment::query()->whereKey($appointment->id)->lockForUpdate()->firstOrFail();

            if (! in_array($status, $lockedAppointment->allowedStatusTransitions(), true)) {
                throw new BusinessConflictException(
                    "Appointment cannot transition from {$lockedAppointment->status} to {$status}.",
                );
            }

            $this->authorizeTransition($lockedAppointment, $status, $actor);
            $previousStatus = $lockedAppointment->status;
            $lockedAppointment->update(['status' => $status]);

            if ($status === Appointment::STATUS_CANCELLED) {
                $this->voucherService->restoreForCancelledAppointment($lockedAppointment);
            } elseif ($status === Appointment::STATUS_COMPLETED && $lockedAppointment->isRegisteredCustomer()) {
                $customer = $lockedAppointment->user()->first();
                if ($customer?->isCustomer()) {
                    $customer->notify(new ReviewInvitationNotification($lockedAppointment));
                    $this->loyaltyService->evaluateAfterCompletion($customer);
                }
            }

            $updatedAppointment = $lockedAppointment->refresh()->load(['user', 'doctor', 'service.category:id,name,slug']);
            $this->logAppointmentTransition(
                $updatedAppointment,
                $previousStatus,
                $this->appointmentAction($status),
            );

            return $updatedAppointment;
        }, 3);
    }

    private function appointmentAction(string $status): string
    {
        return match ($status) {
            Appointment::STATUS_CONFIRMED => AuditLogger::ACTION_CONFIRM,
            Appointment::STATUS_CHECKED_IN => AuditLogger::ACTION_CHECK_IN,
            Appointment::STATUS_IN_PROGRESS => AuditLogger::ACTION_START_EXAMINATION,
            Appointment::STATUS_TREATMENT_DONE => AuditLogger::ACTION_FINISH_EXAMINATION,
            Appointment::STATUS_COMPLETED => AuditLogger::ACTION_COMPLETE,
            Appointment::STATUS_CANCELLED => AuditLogger::ACTION_CANCEL,
            Appointment::STATUS_NO_SHOW => AuditLogger::ACTION_NO_SHOW,
            default => AuditLogger::ACTION_UPDATE,
        };
    }

    private function logAppointmentTransition(
        Appointment $appointment,
        string $previousStatus,
        string $action,
        ?string $actorName = null,
        ?string $actorRole = null,
    ): void {
        $resolvedActorName = request()->user()?->name ?? $actorName ?? 'Khách';
        $verb = match ($action) {
            AuditLogger::ACTION_CONFIRM => 'đã xác nhận',
            AuditLogger::ACTION_CHECK_IN => 'đã check-in',
            AuditLogger::ACTION_START_EXAMINATION => 'đã bắt đầu khám',
            AuditLogger::ACTION_FINISH_EXAMINATION => 'đã kết thúc khám',
            AuditLogger::ACTION_COMPLETE => 'đã hoàn thành',
            AuditLogger::ACTION_CANCEL => 'đã hủy',
            AuditLogger::ACTION_NO_SHOW => 'đã đánh dấu không đến',
            default => 'đã cập nhật',
        };

        $targetName = $this->appointmentTargetName($appointment);
        $this->auditLogger->log(
            $action,
            AuditLogger::MODULE_APPOINTMENT,
            $appointment,
            "{$resolvedActorName} {$verb} lịch hẹn {$targetName}.",
            oldValues: ['status' => $previousStatus],
            newValues: ['status' => $appointment->status],
            targetName: $targetName,
            actorName: $actorName,
            actorRole: $actorRole,
        );
    }

    private function appointmentTargetName(Appointment $appointment): string
    {
        return $appointment->booking_code ?? 'Lịch hẹn #'.$appointment->id;
    }

    private function authorizeTransition(Appointment $appointment, string $status, ?User $actor): void
    {
        if ($status === Appointment::STATUS_NO_SHOW) {
            $startsAt = $appointment->appointment_date->toImmutable()->setTimeFromTimeString($appointment->start_time);

            if (now()->lt($startsAt->addMinutes((int) config('booking.no_show_grace_minutes')))) {
                throw new BusinessConflictException('An appointment can only be marked as no-show after the grace period.');
            }
        }

        if ($actor === null) {
            return;
        }

        if ($actor->isAdmin() || $actor->isReceptionist()) {
            if (! in_array($status, [
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_NO_SHOW,
                Appointment::STATUS_CANCELLED,
                Appointment::STATUS_COMPLETED,
            ], true)) {
                throw new AuthorizationException('Receptionists cannot perform this appointment transition.');
            }
        } elseif ($actor->isDoctor()) {
            if ($actor->doctorProfile?->id !== $appointment->doctor_id
                || ! in_array($status, [Appointment::STATUS_IN_PROGRESS, Appointment::STATUS_TREATMENT_DONE], true)) {
                throw new AuthorizationException('Doctors can only update their own appointments.');
            }
        } else {
            throw new AuthorizationException;
        }

    }

    private function lockDoctor(Doctor $doctor): Doctor
    {
        return Doctor::query()->whereKey($doctor->id)->lockForUpdate()->firstOrFail();
    }

    private function cancelLockedAppointment(Appointment $appointment): Appointment
    {
        if (($reason = $appointment->cancellationBlockReason()) !== null) {
            throw new BusinessConflictException($reason);
        }

        $appointment->update(['status' => Appointment::STATUS_CANCELLED]);
        $this->voucherService->restoreForCancelledAppointment($appointment);

        return $appointment->refresh()->load(['doctor', 'service.category:id,name,slug']);
    }

    private function assertGuestIsBelowActiveAppointmentLimit(string $email): void
    {
        $activeAppointmentCount = Appointment::query()
            ->whereNull('user_id')
            ->where('guest_email', $email)
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            ->where(function (Builder $query): void {
                $query->whereDate('appointment_date', '>', now()->toDateString())
                    ->orWhere(function (Builder $sameDay): void {
                        $sameDay->whereDate('appointment_date', now()->toDateString())
                            ->where('start_time', '>=', now()->format('H:i:s'));
                    });
            })
            ->lockForUpdate()
            ->get(['id'])
            ->count();

        if ($activeAppointmentCount >= (int) config('booking.guest.max_active_appointments')) {
            throw new BusinessConflictException('This email has reached the active appointment limit.');
        }
    }

    private function generateBookingCode(): string
    {
        $createdAt = CarbonImmutable::now();

        return 'JUN-'.$createdAt->format('Ymd-His-v').'-'.Str::upper(Str::random(2));
    }

    private function isBookingCodeCollision(QueryException $exception): bool
    {
        return (int) ($exception->errorInfo[1] ?? 0) === 1062
            && str_contains($exception->getMessage(), 'appointments_booking_code_unique');
    }

    private function assertDateIsNotPast(CarbonImmutable $date): void
    {
        if ($date->startOfDay()->lt(CarbonImmutable::now()->startOfDay())) {
            throw ValidationException::withMessages(['date' => 'The appointment date cannot be in the past.']);
        }
    }

    private function assertDoctorCanPerformService(Doctor $doctor, Service $service): void
    {
        if ($doctor->status !== Doctor::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['doctor_id' => 'The selected doctor is inactive.']);
        }

        if ($service->status !== Service::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['service_id' => 'The selected service is inactive.']);
        }

        if (! $doctor->services()->whereKey($service->id)->exists()) {
            throw ValidationException::withMessages(['service_id' => 'The selected doctor does not provide this service.']);
        }
    }

    /** @param array{day_of_week: int, start_time: string, end_time: string} $data */
    private function assertScheduleDoesNotOverlap(
        Doctor $doctor,
        array $data,
        ?DoctorSchedule $ignoredSchedule = null,
    ): void {
        $overlapExists = $doctor->schedules()
            ->where('day_of_week', $data['day_of_week'])
            ->when($ignoredSchedule, fn (Builder $query): Builder => $query->whereKeyNot($ignoredSchedule->id))
            ->where('start_time', '<', $data['end_time'])
            ->where('end_time', '>', $data['start_time'])
            ->exists();

        if ($overlapExists) {
            throw new BusinessConflictException('The schedule overlaps an existing working period.');
        }
    }

    /** @param list<array{id?: int|null, day_of_week: int, start_time: string, end_time: string}> $schedules */
    private function assertSchedulePayloadDoesNotOverlap(array $schedules): void
    {
        $periodsByDay = collect($schedules)
            ->map(fn (array $schedule): array => [
                'day_of_week' => $schedule['day_of_week'],
                'start_time' => $this->normalizeTime($schedule['start_time']),
                'end_time' => $this->normalizeTime($schedule['end_time']),
            ])
            ->groupBy('day_of_week');

        foreach ($periodsByDay as $periods) {
            $sorted = $periods->sortBy('start_time')->values();

            for ($index = 1; $index < $sorted->count(); $index++) {
                if ($sorted[$index]['start_time'] < $sorted[$index - 1]['end_time']) {
                    throw new BusinessConflictException('The schedule contains overlapping working periods.');
                }
            }
        }
    }

    /** @param list<array{id?: int|null, day_of_week: int, start_time: string, end_time: string}> $schedules */
    private function assertScheduleReplacementKeepsFutureAppointmentsValid(Doctor $doctor, array $schedules): void
    {
        $periods = collect($schedules)->map(fn (array $schedule): array => [
            'day_of_week' => $schedule['day_of_week'],
            'start_time' => $this->normalizeTime($schedule['start_time']),
            'end_time' => $this->normalizeTime($schedule['end_time']),
        ]);

        $conflictingAppointmentIds = $this->futureBlockingAppointmentsQuery($doctor)
            ->lockForUpdate()
            ->get()
            ->reject(function (Appointment $appointment) use ($periods): bool {
                return $periods->contains(fn (array $period): bool => $period['day_of_week'] === $appointment->appointment_date->isoWeekday()
                    && $period['start_time'] <= $appointment->start_time
                    && $period['end_time'] >= $appointment->end_time
                );
            })
            ->modelKeys();

        if ($conflictingAppointmentIds !== []) {
            throw new BusinessConflictException(
                'Không thể cập nhật lịch làm việc vì bác sĩ đang có lịch hẹn tương lai trong khoảng thời gian bị ảnh hưởng. Vui lòng xử lý hoặc đổi lịch hẹn trước.',
                ['appointment_ids' => $conflictingAppointmentIds],
            );
        }
    }

    /** @param array{day_of_week: int, start_time: string, end_time: string}|null $replacement */
    private function assertScheduleChangeKeepsFutureAppointmentsValid(
        Doctor $doctor,
        DoctorSchedule $changedSchedule,
        ?array $replacement = null,
    ): void {
        $periods = $doctor->schedules()
            ->whereKeyNot($changedSchedule->id)
            ->get(['day_of_week', 'start_time', 'end_time'])
            ->map(fn (DoctorSchedule $schedule): array => [
                'day_of_week' => $schedule->day_of_week,
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
            ]);

        if ($replacement !== null) {
            $periods->push([
                ...$replacement,
                'start_time' => $this->normalizeTime($replacement['start_time']),
                'end_time' => $this->normalizeTime($replacement['end_time']),
            ]);
        }

        $conflictingAppointmentIds = $this->futureBlockingAppointmentsQuery($doctor)
            ->lockForUpdate()
            ->get()
            ->reject(function (Appointment $appointment) use ($periods): bool {
                return $periods->contains(fn (array $period): bool => $period['day_of_week'] === $appointment->appointment_date->isoWeekday()
                    && $period['start_time'] <= $appointment->start_time
                    && $period['end_time'] >= $appointment->end_time
                );
            })
            ->modelKeys();

        if ($conflictingAppointmentIds !== []) {
            throw new BusinessConflictException(
                'The schedule change would invalidate future appointments.',
                ['appointment_ids' => $conflictingAppointmentIds],
            );
        }
    }

    /** @param array{date: string, start_time?: ?string, end_time?: ?string} $data */
    private function assertTimeOffDoesNotConflictWithFutureAppointments(Doctor $doctor, array $data): void
    {
        $query = $doctor->appointments()
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            ->whereDate('appointment_date', $data['date']);

        if ($data['date'] === now()->toDateString()) {
            $query->where('start_time', '>=', now()->format('H:i:s'));
        } elseif ($data['date'] < now()->toDateString()) {
            return;
        }

        if (($data['start_time'] ?? null) !== null) {
            $query->where('start_time', '<', $data['end_time'])
                ->where('end_time', '>', $data['start_time']);
        }

        $conflictingAppointmentIds = $query->lockForUpdate()->pluck('id')->all();

        if ($conflictingAppointmentIds !== []) {
            throw new BusinessConflictException(
                'The time off conflicts with future appointments.',
                ['appointment_ids' => $conflictingAppointmentIds],
            );
        }
    }

    private function assertAppointmentCanBeRescheduled(Appointment $appointment): void
    {
        if (($reason = $appointment->rescheduleBlockReason()) !== null) {
            throw new BusinessConflictException($reason);
        }
    }

    private function blockingAppointmentsQuery(
        Doctor $doctor,
        CarbonImmutable $date,
        ?int $excludedAppointmentId = null,
    ): HasMany {
        return $doctor->appointments()
            ->whereDate('appointment_date', $date->toDateString())
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            ->when(
                $excludedAppointmentId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($excludedAppointmentId),
            )
            ->orderBy('start_time')
            ->orderBy('id');
    }

    private function futureBlockingAppointmentsQuery(Doctor $doctor): HasMany
    {
        return $doctor->appointments()
            ->whereIn('status', Appointment::BLOCKING_STATUSES)
            ->where(function (Builder $query): void {
                $query->whereDate('appointment_date', '>', now()->toDateString())
                    ->orWhere(function (Builder $sameDay): void {
                        $sameDay->whereDate('appointment_date', now()->toDateString())
                            ->where('start_time', '>=', now()->format('H:i:s'));
                    });
            });
    }

    private function intervalsOverlap(
        CarbonImmutable $firstStart,
        CarbonImmutable $firstEnd,
        CarbonImmutable $secondStart,
        CarbonImmutable $secondEnd,
    ): bool {
        return $firstStart->lt($secondEnd) && $firstEnd->gt($secondStart);
    }

    private function normalizeTime(string $time): string
    {
        return strlen($time) === 5 ? $time.':00' : $time;
    }
}
