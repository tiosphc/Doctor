<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ReplaceDoctorSchedulesRequest;
use App\Http\Requests\Admin\StoreDoctorScheduleRequest;
use App\Http\Requests\Admin\UpdateDoctorScheduleRequest;
use App\Http\Resources\DoctorScheduleResource;
use App\Models\Doctor;
use App\Models\DoctorSchedule;
use App\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DoctorScheduleController extends Controller
{
    public function index(Doctor $doctor): AnonymousResourceCollection
    {
        return DoctorScheduleResource::collection(
            $doctor->schedules()->orderBy('day_of_week')->orderBy('start_time')->orderBy('id')->get(),
        );
    }

    public function replace(
        ReplaceDoctorSchedulesRequest $request,
        Doctor $doctor,
        BookingService $bookingService,
    ): AnonymousResourceCollection {
        return DoctorScheduleResource::collection(
            $bookingService->replaceSchedules($doctor, $request->validated('schedules')),
        );
    }

    public function store(
        StoreDoctorScheduleRequest $request,
        Doctor $doctor,
        BookingService $bookingService,
    ): JsonResponse {
        $schedule = $bookingService->createSchedule($doctor, $request->validated());

        return (new DoctorScheduleResource($schedule))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        UpdateDoctorScheduleRequest $request,
        Doctor $doctor,
        DoctorSchedule $schedule,
        BookingService $bookingService,
    ): DoctorScheduleResource {
        return new DoctorScheduleResource(
            $bookingService->updateSchedule($doctor, $schedule, $request->validated()),
        );
    }

    public function destroy(
        Doctor $doctor,
        DoctorSchedule $schedule,
        BookingService $bookingService,
    ): Response {
        $bookingService->deleteSchedule($doctor, $schedule);

        return response()->noContent();
    }
}
