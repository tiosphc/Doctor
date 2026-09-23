<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AppointmentIndexRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AppointmentController extends Controller
{
    public function index(AppointmentIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $baseQuery = $this->applyBaseFilters(Appointment::query(), $validated);
        $counts = $this->counts($baseQuery);
        $appointmentsQuery = clone $baseQuery;

        $appointmentsQuery
            ->with(['user', 'doctor', 'service.category:id,name,slug', 'voucher'])
            ->when(
                $validated['status'] ?? null,
                fn (Builder $query, string $status): Builder => $query->where('status', $status),
            )
            ->when(
                ($validated['quick_filter'] ?? 'all') !== 'all',
                fn (Builder $query): Builder => $this->applyQuickFilter(
                    $query,
                    $validated['quick_filter'] ?? 'all',
                ),
            );

        $this->applySort($appointmentsQuery, $validated['sort'] ?? null);

        $appointments = $appointmentsQuery
            ->paginate(10)
            ->withQueryString();

        return AppointmentResource::collection($appointments)->additional(['counts' => $counts]);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load(['user', 'doctor', 'service.category:id,name,slug', 'voucher', 'review.user:id,name']));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyBaseFilters(Builder $query, array $validated): Builder
    {
        return $query
            ->when($validated['doctor_id'] ?? null, fn (Builder $builder, int $doctorId): Builder => $builder->where('doctor_id', $doctorId))
            ->when($validated['service_id'] ?? null, fn (Builder $builder, int $serviceId): Builder => $builder->where('service_id', $serviceId))
            ->when($validated['customer_id'] ?? null, fn (Builder $builder, int $customerId): Builder => $builder->where('user_id', $customerId))
            ->when($validated['date'] ?? null, fn (Builder $builder, string $date): Builder => $builder->whereDate('appointment_date', $date))
            ->when($validated['from'] ?? null, fn (Builder $builder, string $from): Builder => $builder->whereDate('appointment_date', '>=', $from))
            ->when($validated['to'] ?? null, fn (Builder $builder, string $to): Builder => $builder->whereDate('appointment_date', '<=', $to));
    }

    /** @return array{all: int, new: int, upcoming: int, treatment_done: int, completed: int, cancelled: int} */
    private function counts(Builder $baseQuery): array
    {
        $now = now();
        $aggregate = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(*) AS all_count')
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS new_count',
                [Appointment::STATUS_PENDING],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? AND (appointment_date > ? OR (appointment_date = ? AND start_time >= ?)) THEN 1 ELSE 0 END), 0) AS upcoming_count',
                [Appointment::STATUS_CONFIRMED, $now->toDateString(), $now->toDateString(), $now->format('H:i:s')],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS treatment_done_count',
                [Appointment::STATUS_TREATMENT_DONE],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS completed_count',
                [Appointment::STATUS_COMPLETED],
            )
            ->selectRaw(
                'COALESCE(SUM(CASE WHEN status = ? THEN 1 ELSE 0 END), 0) AS cancelled_count',
                [Appointment::STATUS_CANCELLED],
            )
            ->first();

        return [
            'all' => (int) ($aggregate?->all_count ?? 0),
            'new' => (int) ($aggregate?->new_count ?? 0),
            'upcoming' => (int) ($aggregate?->upcoming_count ?? 0),
            'treatment_done' => (int) ($aggregate?->treatment_done_count ?? 0),
            'completed' => (int) ($aggregate?->completed_count ?? 0),
            'cancelled' => (int) ($aggregate?->cancelled_count ?? 0),
        ];
    }

    private function applyQuickFilter(Builder $query, string $quickFilter): Builder
    {
        return match ($quickFilter) {
            'new' => $query->where('status', Appointment::STATUS_PENDING),
            'upcoming' => $this->applyUpcomingFilter($query),
            'completed' => $query->where('status', Appointment::STATUS_COMPLETED),
            'cancelled' => $query->where('status', Appointment::STATUS_CANCELLED),
            'checked_in' => $query->where('status', Appointment::STATUS_CHECKED_IN),
            'in_progress' => $query->where('status', Appointment::STATUS_IN_PROGRESS),
            'treatment_done' => $query->where('status', Appointment::STATUS_TREATMENT_DONE),
            'no_show' => $query->where('status', Appointment::STATUS_NO_SHOW),
            default => $query,
        };
    }

    private function applyUpcomingFilter(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('status', Appointment::STATUS_CONFIRMED)
            ->where(function (Builder $upcoming) use ($now): void {
                $upcoming
                    ->whereDate('appointment_date', '>', $now->toDateString())
                    ->orWhere(function (Builder $sameDay) use ($now): void {
                        $sameDay
                            ->whereDate('appointment_date', $now->toDateString())
                            ->where('start_time', '>=', $now->format('H:i:s'));
                    });
            });
    }

    private function applySort(Builder $query, ?string $sort): void
    {
        if ($sort === 'nearest') {
            $query->orderBy('appointment_date')->orderBy('start_time')->orderBy('id');

            return;
        }

        if ($sort === 'newest') {
            $query->latest('created_at')->orderByDesc('id');

            return;
        }

        if ($sort === 'farthest') {
            $query->orderByDesc('appointment_date')->orderByDesc('start_time')->orderByDesc('id');

            return;
        }

        if ($sort === 'oldest') {
            $query->oldest('created_at')->orderBy('id');

            return;
        }

        $this->applyBusinessPrioritySort($query);
    }

    private function applyBusinessPrioritySort(Builder $query): void
    {
        $query
            ->orderByRaw('CASE status
                WHEN ? THEN 1
                WHEN ? THEN 2
                WHEN ? THEN 3
                WHEN ? THEN 4
                WHEN ? THEN 5
                WHEN ? THEN 6
                WHEN ? THEN 7
                WHEN ? THEN 8
                ELSE 9
            END ASC', [
                Appointment::STATUS_PENDING,
                Appointment::STATUS_CONFIRMED,
                Appointment::STATUS_CHECKED_IN,
                Appointment::STATUS_IN_PROGRESS,
                Appointment::STATUS_TREATMENT_DONE,
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_NO_SHOW,
                Appointment::STATUS_CANCELLED,
            ])
            ->orderByRaw('CASE WHEN status = ? THEN created_at END DESC', [Appointment::STATUS_PENDING])
            ->orderByRaw('CASE WHEN status = ? THEN appointment_date END ASC', [Appointment::STATUS_CONFIRMED])
            ->orderByRaw('CASE WHEN status = ? THEN start_time END ASC', [Appointment::STATUS_CONFIRMED])
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN appointment_date END DESC', [
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
            ])
            ->orderByRaw('CASE WHEN status IN (?, ?) THEN start_time END DESC', [
                Appointment::STATUS_COMPLETED,
                Appointment::STATUS_CANCELLED,
            ])
            ->orderByDesc('id');
    }
}
