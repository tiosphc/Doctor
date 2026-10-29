<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateAppointmentStatusRequest;
use App\Http\Resources\AppointmentResource;
use App\Models\Appointment;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use App\Services\BookingService;

class AppointmentStatusController extends Controller
{
    public function __invoke(
        UpdateAppointmentStatusRequest $request,
        Appointment $appointment,
        BookingService $bookingService,
        AppointmentNotificationService $notificationService,
    ): AppointmentResource {
        $previousStatus = $appointment->status;
        $updatedAppointment = $bookingService->transitionAppointment(
            $appointment,
            $request->validated('status'),
            $request->user(),
        );

        if ($previousStatus === Appointment::STATUS_PENDING && $updatedAppointment->status === Appointment::STATUS_CONFIRMED) {
            $notificationService->send($updatedAppointment, AppointmentNotification::EVENT_CONFIRMED);
        } elseif ($updatedAppointment->status === Appointment::STATUS_CHECKED_IN) {
            $notificationService->sendToDoctor($updatedAppointment, AppointmentNotification::EVENT_CHECKED_IN);
        } elseif ($updatedAppointment->status === Appointment::STATUS_TREATMENT_DONE) {
            $notificationService->sendToStaff($updatedAppointment, AppointmentNotification::EVENT_TREATMENT_DONE, [
                'source' => 'admin_portal',
                'actor_role' => $request->user()->role,
            ]);
        } elseif ($updatedAppointment->status === Appointment::STATUS_COMPLETED) {
            $notificationService->send($updatedAppointment, AppointmentNotification::EVENT_COMPLETED);
        } elseif ($updatedAppointment->status === Appointment::STATUS_CANCELLED) {
            $notificationService->send($updatedAppointment, AppointmentNotification::EVENT_CANCELLED);
            $notificationService->sendToOperations($updatedAppointment, AppointmentNotification::EVENT_CANCELLED);
        }

        return new AppointmentResource(
            $updatedAppointment,
        );
    }
}
