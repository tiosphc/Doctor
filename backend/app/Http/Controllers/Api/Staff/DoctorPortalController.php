<?php

namespace App\Http\Controllers\Api\Staff;

use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\DoctorScheduleResource;
use App\Http\Resources\DoctorTimeOffResource;
use App\Http\Resources\ReviewResource;
use App\Models\Appointment;
use App\Models\Doctor;
use App\Models\Review;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentNotificationService;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Carbon;

class DoctorPortalController extends Controller
{
    private function doctor(Request $request): Doctor
    {
        return $request->user()->doctorProfile;
    }

    public function dashboard(Request $request): array
    {
        $doctor = $this->doctor($request);

        return [
            'today' => $doctor->appointments()->whereDate('appointment_date', now()->toDateString())->count(),
            'checked_in' => $doctor->appointments()->where('status', Appointment::STATUS_CHECKED_IN)->count(),
            'in_progress' => $doctor->appointments()->where('status', Appointment::STATUS_IN_PROGRESS)->count(),
            'treatment_done' => $doctor->appointments()->where('status', Appointment::STATUS_TREATMENT_DONE)->count(),
            'completed' => $doctor->appointments()->where('status', Appointment::STATUS_COMPLETED)->count(),
        ];
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', Appointment::STATUSES)],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $appointments = $this->doctor($request)->appointments()
            ->with(['user', 'doctor', 'service.category:id,name,slug', 'voucher'])
            ->when($validated['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($validated['date'] ?? null, fn (Builder $query, string $date): Builder => $query->whereDate('appointment_date', $date))
            ->orderBy('appointment_date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->paginate(10)
            ->withQueryString();

        return AppointmentResource::collection($appointments);
    }

    public function show(Request $request, Appointment $appointment): AppointmentResource
    {
        abort_unless($appointment->doctor_id === $this->doctor($request)->id, 404);

        return new AppointmentResource($appointment->load(['user', 'doctor', 'service.category:id,name,slug', 'voucher']));
    }

    public function start(Request $request, Appointment $appointment, BookingService $bookingService): AppointmentResource
    {
        abort_unless($appointment->doctor_id === $this->doctor($request)->id, 404);

        return new AppointmentResource($bookingService->transitionAppointment($appointment, Appointment::STATUS_IN_PROGRESS, $request->user()));
    }

    public function complete(
        Request $request,
        Appointment $appointment,
        BookingService $bookingService,
        AppointmentNotificationService $notificationService,
    ): AppointmentResource {
        abort_unless($appointment->doctor_id === $this->doctor($request)->id, 404);

        $updated = $bookingService->transitionAppointment($appointment, Appointment::STATUS_TREATMENT_DONE, $request->user());
        $notificationService->sendToStaff($updated, AppointmentNotification::EVENT_TREATMENT_DONE, [
            'source' => 'doctor_portal',
            'actor_role' => $request->user()->role,
        ]);

        return new AppointmentResource($updated);
    }

    public function reviews(Request $request): AnonymousResourceCollection
    {
        $reviews = $this->doctor($request)->reviews()
            ->where('status', Review::STATUS_PUBLISHED)
            ->with(['user:id,name', 'service:id,name'])
            ->latest('created_at')->orderByDesc('id')->paginate(10)->withQueryString();

        return ReviewResource::collection($reviews);
    }

    public function schedule(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $doctor = $this->doctor($request);
        $from = $validated['from'] ?? now()->toDateString();
        $to = $validated['to'] ?? Carbon::parse($from)->addDays(6)->toDateString();

        return [
            'schedules' => DoctorScheduleResource::collection($doctor->schedules()->orderBy('day_of_week')->orderBy('start_time')->get()),
            'time_offs' => DoctorTimeOffResource::collection($doctor->timeOffs()->whereBetween('date', [$from, $to])->orderBy('date')->orderBy('start_time')->get()),
            'appointments' => AppointmentResource::collection($doctor->appointments()->with(['user', 'doctor', 'service.category:id,name,slug'])->whereBetween('appointment_date', [$from, $to])->orderBy('appointment_date')->orderBy('start_time')->get()),
        ];
    }
}
