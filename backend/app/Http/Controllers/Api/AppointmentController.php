<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppointmentIndexRequest;
use App\Http\Requests\RescheduleAppointmentRequest;
use App\Http\Requests\RescheduleAppointmentSlotsRequest;
use App\Http\Requests\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use App\Services\BookingService;
use App\Services\GuestBookingVerificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class AppointmentController extends Controller
{
    public function index(AppointmentIndexRequest $request): AnonymousResourceCollection
    {
        $validated = $request->validated();
        $appointments = $request->user()->appointments()
            ->with(['doctor', 'service.category:id,name,slug'])
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($request->boolean('upcoming'), function (Builder $query): void {
                $query->where(function (Builder $upcoming): void {
                    $upcoming->whereDate('appointment_date', '>', now()->toDateString())
                        ->orWhere(function (Builder $sameDay): void {
                            $sameDay->whereDate('appointment_date', now()->toDateString())
                                ->where('start_time', '>=', now()->format('H:i:s'));
                        });
                });
            })
            ->orderByDesc('appointment_date')
            ->orderByDesc('start_time')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    public function store(
        StoreAppointmentRequest $request,
        BookingService $bookingService,
        GuestBookingVerificationService $verificationService,
        AppointmentNotificationService $notificationService,
    ): JsonResponse {
        $validated = $request->validated();
        $date = CarbonImmutable::parse($validated['appointment_date'])->startOfDay();

        if ($request->user() !== null) {
            $appointment = $bookingService->createAppointment(
                $request->user(),
                $validated['doctor_id'],
                $validated['service_id'],
                $date,
                $validated['start_time'],
                $validated['note'] ?? null,
                $validated['voucher_id'] ?? null,
            );
        } else {
            $appointment = $verificationService->withVerifiedEmail(
                $validated['guest_email'],
                $validated['verification_token'],
                fn (): Appointment => $bookingService->createGuestAppointment(
                    [
                        'name' => $validated['guest_name'],
                        'email' => $validated['guest_email'],
                        'phone' => $validated['guest_phone'],
                    ],
                    $validated['doctor_id'],
                    $validated['service_id'],
                    $date,
                    $validated['start_time'],
                    $validated['note'] ?? null,
                ),
            );
        }

        $notificationService->send($appointment, AppointmentNotification::EVENT_CREATED);
        $notificationService->sendToStaff($appointment, AppointmentNotification::EVENT_CREATED);
        $notificationService->sendToDoctor($appointment, AppointmentNotification::EVENT_CREATED);

        return (new AppointmentResource($appointment))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        Gate::forUser($request->user())->authorize('view', $appointment);

        return new AppointmentResource($appointment->load(['doctor', 'service.category:id,name,slug', 'review.user:id,name', 'voucher']));
    }

    public function cancel(
        Request $request,
        Appointment $appointment,
        BookingService $bookingService,
        AppointmentNotificationService $notificationService,
    ): AppointmentResource {
        Gate::forUser($request->user())->authorize('cancel', $appointment);

        $cancelledAppointment = $bookingService->cancelAppointment($appointment, $request->user());
        $notificationService->send($cancelledAppointment, AppointmentNotification::EVENT_CANCELLED);
        $notificationService->sendToOperations($cancelledAppointment, AppointmentNotification::EVENT_CANCELLED);

        return new AppointmentResource($cancelledAppointment);
    }

    public function rescheduleSlots(
        RescheduleAppointmentSlotsRequest $request,
        Appointment $appointment,
        BookingService $bookingService,
    ): JsonResponse {
        $date = CarbonImmutable::parse($request->validated('date'))->startOfDay();
        $slots = $bookingService->availableRescheduleSlots(
            $appointment,
            $request->user(),
            $date,
        );

        return response()->json(['data' => [
            'appointment_id' => $appointment->id,
            'doctor_id' => $appointment->doctor_id,
            'service_id' => $appointment->service_id,
            'date' => $date->toDateString(),
            'duration' => $appointment->service()->value('duration'),
            'slots' => $slots,
        ]]);
    }

    public function reschedule(
        RescheduleAppointmentRequest $request,
        Appointment $appointment,
        BookingService $bookingService,
        AppointmentNotificationService $notificationService,
    ): AppointmentResource {
        $validated = $request->validated();
        $result = $bookingService->rescheduleAppointment(
            $appointment,
            $request->user(),
            CarbonImmutable::parse($validated['appointment_date'])->startOfDay(),
            $validated['start_time'],
        );

        $notificationService->send(
            $result->appointment,
            AppointmentNotification::EVENT_RESCHEDULED,
            [
                'previous_schedule' => $result->previousSchedule,
                'new_schedule' => [
                    'appointment_date' => $result->appointment->appointment_date->toDateString(),
                    'start_time' => substr($result->appointment->start_time, 0, 5),
                    'end_time' => substr($result->appointment->end_time, 0, 5),
                ],
            ],
        );
        $notificationService->sendToOperations(
            $result->appointment,
            AppointmentNotification::EVENT_RESCHEDULED,
            [
                'previous_schedule' => $result->previousSchedule,
                'new_schedule' => [
                    'appointment_date' => $result->appointment->appointment_date->toDateString(),
                    'start_time' => substr($result->appointment->start_time, 0, 5),
                    'end_time' => substr($result->appointment->end_time, 0, 5),
                ],
            ],
        );

        return new AppointmentResource($result->appointment);
    }
}
