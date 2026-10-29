<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use Illuminate\Support\Facades\Notification;

class AppointmentNotificationService
{
    /** @param array<string, mixed> $context */
    public function send(Appointment $appointment, string $event, array $context = []): void
    {
        $notification = new AppointmentNotification($appointment, $event, [
            ...$context,
            'audience' => AppointmentNotification::AUDIENCE_CUSTOMER,
        ]);

        if ($appointment->isRegisteredCustomer()) {
            $customer = $appointment->relationLoaded('user')
                ? $appointment->user
                : $appointment->user()->first();

            if ($customer !== null && $customer->isCustomer()) {
                $customer->notify($notification);
            }

            return;
        }

        if ($appointment->guest_email === null) {
            return;
        }

        Notification::route('mail', [
            $appointment->guest_email => $appointment->guest_name ?? $appointment->guest_email,
        ])->notify($notification);
    }

    /** @param array<string, mixed> $context */
    public function sendToAdmins(Appointment $appointment, string $event, array $context = []): void
    {
        $notification = new AppointmentNotification($appointment, $event, [
            ...$context,
            'audience' => AppointmentNotification::AUDIENCE_ADMIN,
        ]);

        User::query()
            ->where('role', User::ROLE_ADMIN)
            ->each(fn (User $admin): mixed => $admin->notify($notification));
    }

    /** @param array<string, mixed> $context */
    public function sendToDoctor(Appointment $appointment, string $event, array $context = []): void
    {
        $appointment->loadMissing(['doctor.user', 'service', 'user']);
        $doctor = $appointment->doctor?->user;

        if ($doctor?->isDoctor()) {
            $doctor->notify(new AppointmentNotification($appointment, $event, [
                ...$context,
                'audience' => AppointmentNotification::AUDIENCE_DOCTOR,
            ]));
        }
    }

    /** @param array<string, mixed> $context */
    public function sendToStaff(Appointment $appointment, string $event, array $context = []): void
    {
        $notification = new AppointmentNotification($appointment, $event, [
            ...$context,
            'audience' => AppointmentNotification::AUDIENCE_STAFF,
        ]);

        User::query()
            ->whereIn('role', [User::ROLE_ADMIN, User::ROLE_RECEPTIONIST])
            ->each(fn (User $recipient): mixed => $recipient->notify($notification));
    }

    /** @param array<string, mixed> $context */
    public function sendToOperations(Appointment $appointment, string $event, array $context = []): void
    {
        $this->sendToStaff($appointment, $event, $context);
        $this->sendToDoctor($appointment, $event, $context);
    }
}
