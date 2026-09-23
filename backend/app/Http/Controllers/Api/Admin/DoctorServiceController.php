<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SyncDoctorServicesRequest;
use App\Http\Resources\AdminDoctorResource;
use App\Models\Doctor;
use App\Services\BookingService;

class DoctorServiceController extends Controller
{
    public function update(
        SyncDoctorServicesRequest $request,
        Doctor $doctor,
        BookingService $bookingService,
    ): AdminDoctorResource {
        return new AdminDoctorResource(
            $bookingService->syncDoctorServices($doctor, $request->validated('service_ids')),
        );
    }
}
