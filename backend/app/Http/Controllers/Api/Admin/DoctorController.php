<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreDoctorRequest;
use App\Http\Requests\Admin\UpdateDoctorRequest;
use App\Http\Resources\AdminDoctorResource;
use App\Models\Doctor;
use App\Services\DoctorAccountService;
use App\Services\DoctorLifecycleService;
use App\Support\PublicImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response;

class DoctorController extends Controller
{
    public function __construct(
        private readonly DoctorAccountService $doctorAccounts,
        private readonly DoctorLifecycleService $doctorLifecycle,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:'.implode(',', Doctor::STATUSES)],
        ]);

        $doctors = Doctor::query()->with('user')->withCount(['services', 'schedules']);

        if (isset($validated['search'])) {
            $search = $validated['search'];

            $doctors->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('specialty', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhereHas('user', fn (Builder $userQuery): Builder => $userQuery
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%'));
            });
        }

        if (isset($validated['status'])) {
            $doctors->where('status', $validated['status']);
        }

        return AdminDoctorResource::collection(
            $doctors->orderBy('name')->orderBy('id')->paginate(15)->withQueryString(),
        );
    }

    public function store(StoreDoctorRequest $request): JsonResponse
    {
        $result = $this->doctorAccounts->create($request->validated());
        $message = $result->invitationQueued
            ? 'Đã tạo bác sĩ và gửi email thiết lập tài khoản.'
            : 'Đã tạo bác sĩ nhưng chưa gửi được email. Bạn có thể gửi lại từ danh sách.';

        return (new AdminDoctorResource($result->doctor))
            ->additional([
                'message' => $message,
                'invitation_queued' => $result->invitationQueued,
            ])
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Doctor $doctor): AdminDoctorResource
    {
        return new AdminDoctorResource(
            $doctor->load(['user', 'services.category:id,name,slug'])->loadCount(['services', 'schedules']),
        );
    }

    public function update(UpdateDoctorRequest $request, Doctor $doctor): AdminDoctorResource
    {
        return new AdminDoctorResource($this->doctorAccounts->update($doctor, $request->validated()));
    }

    public function destroy(Doctor $doctor): \Illuminate\Http\Response
    {
        $avatar = $this->doctorLifecycle->permanentlyDelete($doctor);
        PublicImage::delete($avatar);

        return response()->noContent();
    }
}
