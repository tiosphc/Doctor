<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDoctorTimeOffRequest;
use App\Http\Requests\Admin\UpdateDoctorTimeOffRequest;
use App\Http\Resources\DoctorTimeOffResource;
use App\Models\Doctor;
use App\Models\DoctorTimeOff;
use App\Services\BookingService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class DoctorTimeOffController extends Controller
{
    public function index(Request $request, Doctor $doctor): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $timeOffs = $doctor->timeOffs()
            ->when($validated['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('date', '>=', $from))
            ->when($validated['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('date', '<=', $to))
            ->orderBy('date')
            ->orderBy('start_time')
            ->orderBy('id')
            ->get();

        return DoctorTimeOffResource::collection($timeOffs);
    }

    public function store(
        StoreDoctorTimeOffRequest $request,
        Doctor $doctor,
        BookingService $bookingService,
    ): JsonResponse {
        $timeOff = $bookingService->createTimeOff($doctor, $request->validated());

        return (new DoctorTimeOffResource($timeOff))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        UpdateDoctorTimeOffRequest $request,
        Doctor $doctor,
        DoctorTimeOff $timeOff,
        BookingService $bookingService,
    ): DoctorTimeOffResource {
        return new DoctorTimeOffResource(
            $bookingService->updateTimeOff($doctor, $timeOff, $request->validated()),
        );
    }

    public function destroy(
        Doctor $doctor,
        DoctorTimeOff $timeOff,
        BookingService $bookingService,
    ): Response {
        $bookingService->deleteTimeOff($doctor, $timeOff);

        return response()->noContent();
    }
}
