<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageDiagnosticController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $key = config('storage_diagnostics.key');

        if (! is_string($key) || $key === '') {
            return response()->json(['message' => 'Not Found'], 404)->header('Cache-Control', 'no-store');
        }

        $providedKey = $request->header('X-Diagnostic-Key');

        if (! is_string($providedKey) || ! hash_equals($key, $providedKey)) {
            return response()->json(['message' => 'Forbidden'], 403)->header('Cache-Control', 'no-store');
        }

        $relativeFile = 'products/demo/DEMO-PRD-12.jpg';
        $storageDirectory = storage_path('app/public');
        $storageFile = storage_path('app/public/'.$relativeFile);
        $publicDirectory = public_path('storage');
        $publicFile = public_path('storage/'.$relativeFile);
        $storageRealpath = realpath($storageDirectory);
        $publicRealpath = realpath($publicDirectory);
        $documentRoot = $request->server('DOCUMENT_ROOT');
        $documentRootRealpath = is_string($documentRoot) ? realpath($documentRoot) : false;
        $diskFileExists = false;
        $diskCheckExceptionClass = null;

        try {
            $diskFileExists = Storage::disk('public')->exists($relativeFile);
        } catch (Throwable $exception) {
            report($exception);
            $diskCheckExceptionClass = $exception::class;
        }

        return response()->json([
            'storage_disk_exists' => is_dir($storageDirectory),
            'storage_file_exists' => file_exists($storageFile),
            'storage_file_readable' => is_readable($storageFile),
            'public_storage_exists' => file_exists($publicDirectory) || is_link($publicDirectory),
            'public_storage_is_link' => is_link($publicDirectory),
            'public_storage_is_dir' => is_dir($publicDirectory),
            'public_demo_file_exists' => file_exists($publicFile),
            'public_demo_file_readable' => is_readable($publicFile),
            'storage_realpath_available' => $storageRealpath !== false,
            'public_realpath_available' => $publicRealpath !== false,
            'public_storage_targets_storage_disk' => $storageRealpath !== false && $storageRealpath === $publicRealpath,
            'document_root_available' => $documentRootRealpath !== false,
            'document_root_is_public' => $documentRootRealpath !== false && $documentRootRealpath === realpath(public_path()),
            'document_root_demo_file_exists' => $documentRootRealpath !== false && file_exists($documentRootRealpath.'/storage/'.$relativeFile),
            'filesystem_disk' => config('filesystems.default'),
            'app_url' => config('app.url'),
            'disk_check_exception_class' => $diskCheckExceptionClass,
            'checks' => [
                "Storage::disk('public')->exists('products/demo/DEMO-PRD-12.jpg')" => $diskFileExists,
                "file_exists(storage_path('app/public/products/demo/DEMO-PRD-12.jpg'))" => file_exists($storageFile),
                "file_exists(public_path('storage/products/demo/DEMO-PRD-12.jpg'))" => file_exists($publicFile),
            ],
        ])->header('Cache-Control', 'no-store');
    }
}
