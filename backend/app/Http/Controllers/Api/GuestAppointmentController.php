<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\GuestAppointmentCancelRequest;
use App\Http\Requests\GuestAppointmentLookupRequest;
use App\Http\Requests\GuestAppointmentRescheduleRequest;
use App\Http\Requests\GuestAppointmentRescheduleSlotsRequest;
use App\Http\Resources\PublicAppointmentResource;
use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use App\Services\BookingService;
use App\Support\PhoneNumber;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class GuestAppointmentController extends Controller
{
    public function lookup(GuestAppointmentLookupRequest $request): PublicAppointmentResource
    {
        $validated = $request->validated();

        return new PublicAppointmentResource($this->findPublicAppointment(
            $validated['booking_code'],
            $validated['phone'],
        ));
    }

    public function cancel(
        GuestAppointmentCancelRequest $request,
        string $bookingCode,
        BookingService $bookingService,
        AppointmentNotificationService $notificationService,
    ): PublicAppointmentResource {
        $phone = $request->validated('phone');
        $appointment = $this->findPublicAppointment($bookingCode, $phone);

        $cancelledAppointment = $bookingService->cancelPublicAppointment($appointment, $phone);
        $notificationService->send($cancelledAppointment, AppointmentNotification::EVENT_CANCELLED);
        $notificationService->sendToOperations($cancelledAppointment, AppointmentNotification::EVENT_CANCELLED);

        return new PublicAppointmentResource($cancelledAppointment);
    }

    public function rescheduleSlots(
        GuestAppointmentRescheduleSlotsRequest $request,
        string $bookingCode,
        BookingService $bookingService,
    ): JsonResponse {
        $validated = $request->validated();
        $appointment = $this->findPublicAppointment($bookingCode, $validated['phone']);
        $date = CarbonImmutable::parse($validated['date'])->startOfDay();

        return response()->json(['data' => [
            'date' => $date->toDateString(),
            'duration' => $appointment->service->duration,
            'slots' => $bookingService->availablePublicRescheduleSlots($appointment, $date),
        ]]);
    }

    public function reschedule(
        GuestAppointmentRescheduleRequest $request,
        string $bookingCode,
        BookingService $bookingService,
        AppointmentNotificationService $notificationService,
    ): PublicAppointmentResource {
        $validated = $request->validated();
        $appointment = $this->findPublicAppointment($bookingCode, $validated['phone']);
        $result = $bookingService->reschedulePublicAppointment(
            $appointment,
            CarbonImmutable::parse($validated['appointment_date'])->startOfDay(),
            $validated['start_time'],
            $validated['phone'],
        );
        $context = [
            'previous_schedule' => $result->previousSchedule,
            'new_schedule' => [
                'appointment_date' => $result->appointment->appointment_date->toDateString(),
                'start_time' => substr($result->appointment->start_time, 0, 5),
                'end_time' => substr($result->appointment->end_time, 0, 5),
            ],
        ];

        $notificationService->send($result->appointment, AppointmentNotification::EVENT_RESCHEDULED, $context);
        $notificationService->sendToOperations($result->appointment, AppointmentNotification::EVENT_RESCHEDULED, $context);

        return new PublicAppointmentResource($result->appointment);
    }

    private function findPublicAppointment(string $bookingCode, string $phone): Appointment
    {
        $appointment = Appointment::query()
            ->where('booking_code', Str::upper(trim($bookingCode)))
            ->with(['user:id,name,phone', 'doctor', 'service.category:id,name,slug'])
            ->first();

        if ($appointment === null) {
            abort(404, 'Không tìm thấy lịch hẹn phù hợp với thông tin đã cung cấp.');
        }

        $appointmentPhone = $appointment->isGuest()
            ? $appointment->guest_phone
            : $appointment->user?->phone;

        if ($appointmentPhone === null || ! hash_equals(
            PhoneNumber::normalize($appointmentPhone),
            PhoneNumber::normalize($phone),
        )) {
            abort(404, 'Không tìm thấy lịch hẹn phù hợp với thông tin đã cung cấp.');
        }

        return $appointment;
    }
}
