<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\CustomerResource;
use App\Models\Appointment;
use App\Models\Customer;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceptionistAppointmentController extends Controller
{
    public function dashboard(): array
    {
        $today = now()->toDateString();

        return [
            'today' => Appointment::query()->whereDate('appointment_date', $today)->count(),
            'pending' => Appointment::query()->where('status', Appointment::STATUS_PENDING)->count(),
            'confirmed' => Appointment::query()->where('status', Appointment::STATUS_CONFIRMED)->count(),
            'checked_in' => Appointment::query()->where('status', Appointment::STATUS_CHECKED_IN)->count(),
            'in_progress' => Appointment::query()->where('status', Appointment::STATUS_IN_PROGRESS)->count(),
            'treatment_done' => Appointment::query()->where('status', Appointment::STATUS_TREATMENT_DONE)->count(),
            'completed' => Appointment::query()->where('status', Appointment::STATUS_COMPLETED)->count(),
            'cancelled' => Appointment::query()->where('status', Appointment::STATUS_CANCELLED)->count(),
            'no_show' => Appointment::query()->where('status', Appointment::STATUS_NO_SHOW)->count(),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Appointment::STATUSES)],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $appointments = Appointment::query()
            ->with(['user', 'doctor', 'service.category:id,name,slug', 'voucher'])
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($validated['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('appointment_date', $date))
            ->when($validated['search'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $builder) use ($search): void {
                    $builder->where('guest_name', 'like', '%'.$search.'%')
                        ->orWhere('guest_email', 'like', '%'.$search.'%')
                        ->orWhereHas('user', fn (Builder $user): Builder => $user->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
                });
            })
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    public function show(Appointment $appointment): AppointmentResource
    {
        return new AppointmentResource($appointment->load(['user', 'doctor', 'service.category:id,name,slug', 'voucher']));
    }

    public function confirm(Appointment $appointment, BookingService $bookingService, AppointmentNotificationService $notificationService): AppointmentResource
    {
        return $this->transition($appointment, Appointment::STATUS_CONFIRMED, $bookingService, $notificationService);
    }

    public function checkIn(Appointment $appointment, BookingService $bookingService, AppointmentNotificationService $notificationService): AppointmentResource
    {
        return $this->transition($appointment, Appointment::STATUS_CHECKED_IN, $bookingService, $notificationService);
    }

    public function complete(Appointment $appointment, BookingService $bookingService, AppointmentNotificationService $notificationService): AppointmentResource
    {
        return $this->transition($appointment, Appointment::STATUS_COMPLETED, $bookingService, $notificationService);
    }

    public function noShow(Appointment $appointment, BookingService $bookingService, AppointmentNotificationService $notificationService): AppointmentResource
    {
        return $this->transition($appointment, Appointment::STATUS_NO_SHOW, $bookingService, $notificationService);
    }

    public function cancel(Appointment $appointment, BookingService $bookingService, AppointmentNotificationService $notificationService): AppointmentResource
    {
        return $this->transition($appointment, Appointment::STATUS_CANCELLED, $bookingService, $notificationService);
    }

    private function transition(Appointment $appointment, string $status, BookingService $bookingService, AppointmentNotificationService $notificationService): AppointmentResource
    {
        $updated = $bookingService->transitionAppointment($appointment, $status, request()->user());

        if ($updated->status === Appointment::STATUS_CANCELLED) {
            $notificationService->send($updated, AppointmentNotification::EVENT_CANCELLED);
            $notificationService->sendToOperations($updated, AppointmentNotification::EVENT_CANCELLED);
        } elseif ($updated->status === Appointment::STATUS_CONFIRMED) {
            $notificationService->send($updated, AppointmentNotification::EVENT_CONFIRMED);
        } elseif ($updated->status === Appointment::STATUS_CHECKED_IN) {
            $notificationService->sendToDoctor($updated, AppointmentNotification::EVENT_CHECKED_IN);
        } elseif ($updated->status === Appointment::STATUS_COMPLETED) {
            $notificationService->send($updated, AppointmentNotification::EVENT_COMPLETED);
        }

        return new AppointmentResource($updated);
    }

    public function customers(Request $request): AnonymousResourceCollection
    {
        $search = $request->string('search')->trim()->toString();
        $customers = Customer::query()
            ->with('user:id,role')
            ->when($search !== '', fn (Builder $query): Builder => $query->where(fn (Builder $builder): Builder => $builder
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('primary_email', 'like', '%'.$search.'%')
                ->orWhere('primary_phone', 'like', '%'.$search.'%')
                ->orWhere('customer_code', 'like', '%'.$search.'%')))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        return CustomerResource::collection($customers);
    }
}
