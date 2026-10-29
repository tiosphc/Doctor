<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('notifications')
            ->join('users', function ($join): void {
                $join->on('users.id', '=', 'notifications.notifiable_id')
                    ->where('notifications.notifiable_type', '=', 'App\\Models\\User');
            })
            ->where('users.role', 'doctor')
            ->where('notifications.type', 'App\\Notifications\\AppointmentNotification')
            ->where('notifications.data->event', 'appointment_created')
            ->select([
                'notifications.id',
                'notifications.notifiable_id as recipient_id',
                'notifications.data',
            ])
            ->orderBy('notifications.id')
            ->chunk(100, function (Collection $notifications): void {
                $appointmentIds = $notifications
                    ->map(fn (object $notification): ?int => $this->appointmentId($notification->data))
                    ->filter()
                    ->unique()
                    ->values();

                $appointments = DB::table('appointments')
                    ->join('doctors', 'doctors.id', '=', 'appointments.doctor_id')
                    ->join('services', 'services.id', '=', 'appointments.service_id')
                    ->leftJoin('users as customers', 'customers.id', '=', 'appointments.user_id')
                    ->whereIn('appointments.id', $appointmentIds)
                    ->select([
                        'appointments.id',
                        'appointments.appointment_date',
                        'appointments.start_time',
                        'appointments.end_time',
                        'appointments.guest_name',
                        'doctors.user_id as doctor_user_id',
                        'doctors.name as doctor_name',
                        'services.name as service_name',
                        'customers.name as customer_name',
                    ])
                    ->get()
                    ->keyBy('id');

                foreach ($notifications as $notification) {
                    $data = json_decode($notification->data, true);
                    $appointmentId = $this->appointmentId($notification->data);
                    $appointment = $appointmentId === null ? null : $appointments->get($appointmentId);

                    if (! is_array($data)
                        || $appointment === null
                        || (int) $appointment->doctor_user_id !== (int) $notification->recipient_id
                        || ! $this->hasCustomerCopy($data)) {
                        continue;
                    }

                    $customerName = $appointment->customer_name
                        ?? $appointment->guest_name
                        ?? 'Khách hàng';

                    $data['title'] = 'Có lịch hẹn mới';
                    $data['message'] = 'Bạn có lịch hẹn mới với '.$customerName.' cho dịch vụ '.$appointment->service_name.'.';
                    $data['audience'] = 'doctor';
                    $data['action_url'] = rtrim((string) config('app.frontend_url'), '/')
                        .'/doctor/appointments/'.$appointmentId;
                    $data['data'] = [
                        'customer_name' => $customerName,
                        'service_name' => $appointment->service_name,
                        'doctor_name' => $appointment->doctor_name,
                        'appointment_date' => $appointment->appointment_date,
                        'start_time' => substr((string) $appointment->start_time, 0, 5),
                        'end_time' => substr((string) $appointment->end_time, 0, 5),
                    ];

                    DB::table('notifications')
                        ->where('id', $notification->id)
                        ->update([
                            'data' => json_encode(
                                $data,
                                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
                            ),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        /**
         * The original incorrect audience-specific copy cannot be reconstructed safely.
         * Keep the corrected notification payloads on rollback.
         */
    }

    private function appointmentId(string $data): ?int
    {
        $payload = json_decode($data, true);

        if (! is_array($payload) || ! is_numeric($payload['appointment_id'] ?? null)) {
            return null;
        }

        return (int) $payload['appointment_id'];
    }

    /** @param array<string, mixed> $data */
    private function hasCustomerCopy(array $data): bool
    {
        return ($data['title'] ?? null) === 'Đặt lịch thành công'
            || str_contains((string) ($data['message'] ?? ''), 'Lịch hẹn của bạn');
    }
};
