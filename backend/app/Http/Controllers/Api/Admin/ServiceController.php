<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreServiceRequest;
use App\Http\Requests\Admin\UpdateServiceRequest;
use App\Http\Resources\AdminServiceResource;
use App\Models\Service;
use App\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ServiceController extends Controller
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $services = Service::query()->with('category:id,name,slug');

        if (isset($validated['search'])) {
            $search = $validated['search'];

            $services->where(function (Builder $query) use ($search): void {
                $query->where('name', 'like', '%'.$search.'%')
                    ->orWhereHas('category', function (Builder $query) use ($search): void {
                        $query->where('name', 'like', '%'.$search.'%');
                    });
            });
        }

        return AdminServiceResource::collection(
            $services->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate($validated['per_page'] ?? 5)->withQueryString(),
        );
    }

    public function store(StoreServiceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $newPaths = [];

        try {
            $validated = $this->prepareUploadedFields($request, $validated, null, $newPaths);
            $service = DB::transaction(function () use ($validated, $request): Service {
                $service = Service::create($validated);
                $this->auditLogger->log(
                    AuditLogger::ACTION_CREATE,
                    AuditLogger::MODULE_SERVICE,
                    $service,
                    "{$request->user()->name} đã tạo dịch vụ {$service->name}.",
                    newValues: $service->only(['name', 'price', 'duration', 'status']),
                );

                return $service;
            });
        } catch (Throwable $exception) {
            $this->deleteLocalImages($newPaths);

            throw $exception;
        }

        return (new AdminServiceResource($service->refresh()->load('category:id,name,slug')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Service $service): AdminServiceResource
    {
        return new AdminServiceResource($service->load('category:id,name,slug'));
    }

    public function update(UpdateServiceRequest $request, Service $service): AdminServiceResource
    {
        $validated = $request->validated();
        $oldPaths = $this->serviceImagePaths($service);
        $newPaths = [];

        try {
            $validated = $this->prepareUploadedFields($request, $validated, $service, $newPaths);

            DB::transaction(function () use ($service, $validated, $request): void {
                $trackedFields = array_values(array_intersect(
                    ['name', 'price', 'duration', 'status'],
                    array_keys($validated),
                ));
                $before = $service->only($trackedFields);
                $service->update($validated);
                $service->refresh();
                $diff = $this->auditLogger->diff($before, $service->only($trackedFields));

                if ($diff['old'] !== []) {
                    $action = match ($diff['new']['status'] ?? null) {
                        Service::STATUS_ACTIVE => AuditLogger::ACTION_ACTIVATE,
                        Service::STATUS_INACTIVE => AuditLogger::ACTION_DEACTIVATE,
                        default => AuditLogger::ACTION_UPDATE,
                    };
                    $verb = match ($action) {
                        AuditLogger::ACTION_ACTIVATE => 'kích hoạt',
                        AuditLogger::ACTION_DEACTIVATE => 'ngừng hoạt động',
                        default => 'cập nhật',
                    };
                    $this->auditLogger->log(
                        $action,
                        AuditLogger::MODULE_SERVICE,
                        $service,
                        "{$request->user()->name} đã {$verb} dịch vụ {$service->name}.",
                        oldValues: $diff['old'],
                        newValues: $diff['new'],
                    );
                }
            });
        } catch (Throwable $exception) {
            $this->deleteLocalImages($newPaths);

            throw $exception;
        }

        $service->refresh();
        $this->deleteSupersededImages($oldPaths, $this->serviceImagePaths($service));

        return new AdminServiceResource($service->load('category:id,name,slug'));
    }

    public function destroy(Service $service): HttpResponse|JsonResponse
    {
        if ($service->appointments()->exists()) {
            return response()->json([
                'message' => 'Service cannot be deleted because appointment history exists. Deactivate the service instead.',
            ], Response::HTTP_CONFLICT);
        }

        $paths = $this->serviceImagePaths($service);
        $deleted = DB::transaction(function () use ($service): bool {
            $this->auditLogger->log(
                AuditLogger::ACTION_DELETE,
                AuditLogger::MODULE_SERVICE,
                $service,
                request()->user()->name." đã xóa dịch vụ {$service->name}.",
                oldValues: $service->only(['name', 'price', 'duration', 'status']),
            );

            return (bool) $service->delete();
        });
        if ($deleted) {
            $this->deleteLocalImages($paths);
        }

        return response()->noContent();
    }

    /** @param array<string, string> $newPaths */
    private function prepareUploadedFields(
        Request $request,
        array $validated,
        ?Service $service,
        array &$newPaths,
    ): array {
        foreach ([
            'image' => 'services',
            'hero_image' => 'service-heroes',
        ] as $field => $directory) {
            if (! array_key_exists($field, $validated) || ! $validated[$field] instanceof UploadedFile) {
                continue;
            }

            $path = $this->storeUploadedImage($validated[$field], $directory);
            $newPaths[] = $path;
            $validated[$field] = $path;
        }

        $resultImages = $request->file('result_images', []);
        $hasContent = array_key_exists('content', $validated);
        $content = $hasContent ? $validated['content'] : $service?->content;

        if (is_array($content) && is_array($content['results']['cases'] ?? null)) {
            $content['results']['cases'] = $this->preserveResultImagePaths(
                $content['results']['cases'],
                $service?->content,
                $resultImages,
            );

            foreach ($resultImages as $index => $files) {
                if (! is_array($files) || ! array_key_exists((int) $index, $content['results']['cases'])) {
                    continue;
                }

                foreach (['before_image', 'after_image'] as $side) {
                    $file = $files[$side] ?? null;

                    if (! $file instanceof UploadedFile) {
                        continue;
                    }

                    $path = $this->storeUploadedImage($file, 'service-results');
                    $newPaths[] = $path;
                    $content['results']['cases'][(int) $index][$side] = $path;
                }
            }

            $validated['content'] = $content;
        }

        unset($validated['result_images']);

        return $validated;
    }

    private function storeUploadedImage(UploadedFile $image, string $directory): string
    {
        $path = $image->store($directory, 'public');

        if (! is_string($path)) {
            throw new \RuntimeException('Unable to store the service image.');
        }

        return $path;
    }

    /** @param list<string> $paths */
    private function deleteLocalImages(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            $this->deleteLocalImage($path);
        }
    }

    private function deleteLocalImage(?string $image): void
    {
        if ($image === null || filter_var($image, FILTER_VALIDATE_URL) !== false || str_starts_with($image, '//')) {
            return;
        }

        Storage::disk('public')->delete($image);
    }

    /** @return list<string> */
    private function serviceImagePaths(Service $service): array
    {
        $paths = array_filter([$service->image, $service->hero_image], fn (mixed $path): bool => is_string($path));

        if (is_array($service->content) && is_array($service->content['results']['cases'] ?? null)) {
            foreach ($service->content['results']['cases'] as $case) {
                if (! is_array($case)) {
                    continue;
                }

                foreach (['before_image', 'after_image'] as $side) {
                    if (is_string($case[$side] ?? null)) {
                        $paths[] = $case[$side];
                    }
                }
            }
        }

        if (is_array($service->content) && is_array($service->content['blocks'] ?? null)) {
            foreach ($service->content['blocks'] as $block) {
                if (is_array($block) && is_string($block['image'] ?? null)) {
                    $paths[] = $block['image'];
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<array<string, mixed>>  $cases
     * @param  array<string, mixed>|null  $oldContent
     * @param  array<int|string, mixed>  $resultImages
     * @return list<array<string, mixed>>
     */
    private function preserveResultImagePaths(array $cases, ?array $oldContent, array $resultImages): array
    {
        $oldCases = is_array($oldContent['results']['cases'] ?? null)
            ? $oldContent['results']['cases']
            : [];

        foreach ($cases as $index => &$case) {
            if (! is_array($case)) {
                continue;
            }

            $oldCase = is_array($oldCases[$index] ?? null) ? $oldCases[$index] : [];
            $uploadedSides = is_array($resultImages[$index] ?? null) ? $resultImages[$index] : [];

            foreach (['before_image', 'after_image'] as $side) {
                if (($uploadedSides[$side] ?? null) instanceof UploadedFile) {
                    continue;
                }

                if (! isset($case[$side]) || $case[$side] === '') {
                    $case[$side] = $oldCase[$side] ?? null;
                }
            }
        }
        unset($case);

        return array_values($cases);
    }

    /** @param list<string> $oldPaths @param list<string> $newPaths */
    private function deleteSupersededImages(array $oldPaths, array $newPaths): void
    {
        foreach (array_diff($oldPaths, $newPaths) as $path) {
            $this->deleteLocalImage($path);
        }
    }
}
