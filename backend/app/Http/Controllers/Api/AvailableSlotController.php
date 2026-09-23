<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AvailableSlotsRequest;
use App\Models\Doctor;
use App\Models\Service;
use App\Services\BookingService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class AvailableSlotController extends Controller
{
    public function __invoke(
        AvailableSlotsRequest $request,
        Doctor $doctor,
        BookingService $bookingService,
    ): JsonResponse {
        $validated = $request->validated();
        $service = Service::findOrFail($validated['service_id']);
        $date = CarbonImmutable::parse($validated['date'])->startOfDay();

        return response()->json(['data' => [
            'doctor_id' => $doctor->id,
            'service_id' => $service->id,
            'date' => $date->toDateString(),
            'duration' => $service->duration,
            'slots' => $bookingService->availableSlots($doctor, $service, $date),
        ]]);
    }
}
