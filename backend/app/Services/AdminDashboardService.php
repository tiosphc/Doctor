<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Doctor;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Models\Voucher;
use Carbon\CarbonImmutable;

class AdminDashboardService
{
    public const PERIOD_SEVEN_DAYS = '7_days';

    public const PERIOD_THIRTY_DAYS = '30_days';

    public const PERIOD_MONTH = 'month';

    public const PERIODS = [
        self::PERIOD_SEVEN_DAYS,
        self::PERIOD_THIRTY_DAYS,
        self::PERIOD_MONTH,
    ];

    /** @return array<string, mixed> */
    public function summarize(string $period): array
    {
        $now = now()->toImmutable();

        return [
            'generated_at' => $now->toIso8601String(),
            'timezone' => config('app.timezone'),
            'overview' => $this->overview(),
            'today' => $this->today($now),
            'today_appointments' => $this->todayAppointments($now),
            'appointment_statistics' => $this->appointmentStatistics($period, $now),
            'actions_required' => $this->actionsRequired($now),
            'recent_activities' => $this->recentActivities(),
            'doctor_today' => $this->doctorToday($now),
            'popular_services' => $this->popularServices($now),
        ];
    }

    /** @return array{available: true, items: list<array{id: int, occurred_at: string, actor: string, action: string, subject: string}>} */
    private function recentActivities(): array
    {
        $items = AuditLog::query()
            ->select(['id', 'actor_name', 'action', 'target_name', 'description', 'created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(8)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'occurred_at' => $log->created_at->toIso8601String(),
                'actor' => $log->actor_name,
                'action' => $log->description,
                'subject' => $log->target_name,
            ])
            ->all();

        return ['available' => true, 'items' => $items];
    }

    /** @return array{doctors: int, services: int, appointments: int, customers: int} */
    private function overview(): array
    {
        return [
            'doctors' => Doctor::query()->count(),
            'services' => Service::query()->count(),
            'appointments' => Appointment::query()->count(),
            'customers' => User::query()->where('role', User::ROLE_CUSTOMER)->count(),
        ];
    }

    /** @return array<string, int|string> */
    private function today(CarbonImmutable $now): array
    {
        $aggregate = Appointment::query()
            ->whereDate('appointment_date', $now->toDateString())
            ->toBase()
            ->selectRaw('COUNT(*) AS appointments_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS waiting_checkin_count', [Appointment::STATUS_CONFIRMED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS checked_in_count', [Appointment::STATUS_CHECKED_IN])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS in_progress_count', [Appointment::STATUS_IN_PROGRESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_count', [Appointment::STATUS_COMPLETED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS cancelled_count', [Appointment::STATUS_CANCELLED])
            ->first();

        return [
            'date' => $now->toDateString(),
            'appointments' => (int) ($aggregate?->appointments_count ?? 0),
            'waiting_checkin' => (int) ($aggregate?->waiting_checkin_count ?? 0),
            'checked_in' => (int) ($aggregate?->checked_in_count ?? 0),
            'in_progress' => (int) ($aggregate?->in_progress_count ?? 0),
            'completed' => (int) ($aggregate?->completed_count ?? 0),
            'cancelled' => (int) ($aggregate?->cancelled_count ?? 0),
        ];
    }

    /** @return list<array<string, int|string|null>> */
    private function todayAppointments(CarbonImmutable $now): array
    {
        $time = $now->format('H:i:s');

        return Appointment::query()
            ->select([
                'id',
                'user_id',
                'guest_name',
                'booking_code',
                'doctor_id',
                'service_id',
                'appointment_date',
                'start_time',
                'end_time',
                'status',
            ])
            ->with(['user:id,name', 'doctor:id,name', 'service:id,name'])
            ->whereDate('appointment_date', $now->toDateString())
            ->orderByRaw('CASE WHEN start_time >= ? THEN 0 ELSE 1 END', [$time])
            ->orderByRaw('CASE WHEN start_time >= ? THEN start_time END ASC', [$time])
            ->orderByRaw('CASE WHEN start_time < ? THEN start_time END DESC', [$time])
            ->orderBy('id')
            ->limit(8)
            ->get()
            ->map(fn (Appointment $appointment): array => [
                'id' => $appointment->id,
                'booking_code' => $appointment->booking_code,
                'customer_name' => $appointment->isGuest()
                    ? $appointment->guest_name
                    : $appointment->user?->name,
                'doctor_name' => $appointment->doctor->name,
                'service_name' => $appointment->service->name,
                'appointment_date' => $appointment->appointment_date->toDateString(),
                'start_time' => $appointment->start_time,
                'end_time' => $appointment->end_time,
                'status' => $appointment->status,
            ])
            ->all();
    }

    /** @return array{period: string, from: string, to: string, items: list<array{date: string, total: int, completed: int, cancelled: int}>} */
    private function appointmentStatistics(string $period, CarbonImmutable $now): array
    {
        [$from, $to] = $this->statisticsRange($period, $now);
        $rows = Appointment::query()
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->toBase()
            ->selectRaw('appointment_date AS date')
            ->selectRaw('COUNT(*) AS total_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_count', [Appointment::STATUS_COMPLETED])
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS cancelled_count', [Appointment::STATUS_CANCELLED])
            ->groupBy('appointment_date')
            ->orderBy('appointment_date')
            ->get()
            ->keyBy(fn (object $row): string => (string) $row->date);

        $items = [];
        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $row = $rows->get($key);
            $items[] = [
                'date' => $key,
                'total' => (int) ($row?->total_count ?? 0),
                'completed' => (int) ($row?->completed_count ?? 0),
                'cancelled' => (int) ($row?->cancelled_count ?? 0),
            ];
        }

        return [
            'period' => $period,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'items' => $items,
        ];
    }

    /** @return array{CarbonImmutable, CarbonImmutable} */
    private function statisticsRange(string $period, CarbonImmutable $now): array
    {
        return match ($period) {
            self::PERIOD_THIRTY_DAYS => [$now->subDays(29)->startOfDay(), $now->endOfDay()],
            self::PERIOD_MONTH => [$now->startOfMonth(), $now->endOfMonth()],
            default => [$now->subDays(6)->startOfDay(), $now->endOfDay()],
        };
    }

    /** @return list<array{key: string, label: string, count: int, url: string}> */
    private function actionsRequired(CarbonImmutable $now): array
    {
        $appointmentCounts = Appointment::query()
            ->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS pending_count', [Appointment::STATUS_PENDING])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND appointment_date = ? AND start_time < ? THEN 1 ELSE 0 END), 0) AS overdue_checkin_count',
                [Appointment::STATUS_CONFIRMED, $now->toDateString(), $now->format('H:i:s')],
            )
            ->selectRaw('COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS treatment_done_count', [Appointment::STATUS_TREATMENT_DONE])
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND appointment_date = ? THEN 1 ELSE 0 END), 0) AS cancelled_today_count',
                [Appointment::STATUS_CANCELLED, $now->toDateString()],
            )
            ->first();

        $items = collect([
            ['key' => 'pending', 'label' => 'lịch đang chờ xác nhận', 'count' => (int) ($appointmentCounts?->pending_count ?? 0), 'url' => '/admin/appointments'],
            ['key' => 'overdue_checkin', 'label' => 'lịch đã đến giờ nhưng chưa check-in', 'count' => (int) ($appointmentCounts?->overdue_checkin_count ?? 0), 'url' => '/admin/appointments'],
            ['key' => 'treatment_done', 'label' => 'lịch chờ hoàn tất', 'count' => (int) ($appointmentCounts?->treatment_done_count ?? 0), 'url' => '/admin/appointments'],
            ['key' => 'cancelled_today', 'label' => 'lịch đã hủy hôm nay', 'count' => (int) ($appointmentCounts?->cancelled_today_count ?? 0), 'url' => '/admin/appointments'],
            ['key' => 'new_reviews', 'label' => 'đánh giá mới hôm nay', 'count' => Review::query()->whereDate('created_at', $now->toDateString())->count(), 'url' => '/admin/reviews'],
            [
                'key' => 'expiring_vouchers',
                'label' => 'voucher sắp hết hạn trong 7 ngày',
                'count' => Voucher::query()
                    ->where('status', Voucher::STATUS_ACTIVE)
                    ->whereBetween('expires_at', [$now, $now->addDays(7)->endOfDay()])
                    ->count(),
                'url' => '/admin/vouchers',
            ],
        ]);

        return $items
            ->filter(fn (array $item): bool => $item['count'] > 0)
            ->values()
            ->all();
    }

    /** @return list<array{id: int, name: string, appointments: int, in_progress: int, completed: int}> */
    private function doctorToday(CarbonImmutable $now): array
    {
        return Appointment::query()
            ->toBase()
            ->join('doctors', 'doctors.id', '=', 'appointments.doctor_id')
            ->whereDate('appointments.appointment_date', $now->toDateString())
            ->select(['doctors.id', 'doctors.name'])
            ->selectRaw('COUNT(*) AS appointments_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN appointments.status = ? THEN 1 ELSE 0 END), 0) AS in_progress_count', [Appointment::STATUS_IN_PROGRESS])
            ->selectRaw('COALESCE(SUM(CASE WHEN appointments.status = ? THEN 1 ELSE 0 END), 0) AS completed_count', [Appointment::STATUS_COMPLETED])
            ->groupBy('doctors.id', 'doctors.name')
            ->orderByDesc('appointments_count')
            ->orderBy('doctors.name')
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'appointments' => (int) $row->appointments_count,
                'in_progress' => (int) $row->in_progress_count,
                'completed' => (int) $row->completed_count,
            ])
            ->all();
    }

    /** @return list<array{id: int, name: string, appointments: int}> */
    private function popularServices(CarbonImmutable $now): array
    {
        return Appointment::query()
            ->toBase()
            ->join('services', 'services.id', '=', 'appointments.service_id')
            ->whereBetween('appointments.appointment_date', [
                $now->startOfMonth()->toDateString(),
                $now->endOfMonth()->toDateString(),
            ])
            ->where('appointments.status', '!=', Appointment::STATUS_CANCELLED)
            ->select(['services.id', 'services.name'])
            ->selectRaw('COUNT(*) AS appointments_count')
            ->groupBy('services.id', 'services.name')
            ->orderByDesc('appointments_count')
            ->orderBy('services.name')
            ->limit(5)
            ->get()
            ->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'name' => $row->name,
                'appointments' => (int) $row->appointments_count,
            ])
            ->all();
    }
}
