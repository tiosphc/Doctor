<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\Doctor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Throwable;

class AuthDiagnosticController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        if (($denied = $this->denyWithoutKey($request)) !== null) {
            return $denied;
        }

        $usersTableExists = false;
        $sessionsTableExists = false;
        $sessionTableColumnsValid = false;
        $admin = null;
        $databaseExceptionClass = null;

        try {
            $usersTableExists = Schema::hasTable('users');

            if ($usersTableExists) {
                $admin = DB::table('users')->where('email', 'admin@demo.local')->first(['password']);
            }

            $sessionTable = config('session.table', 'sessions');
            $sessionConnection = config('session.connection');
            $sessionSchema = is_string($sessionConnection) && $sessionConnection !== ''
                ? Schema::connection($sessionConnection)
                : Schema::getFacadeRoot();

            if (is_string($sessionTable) && $sessionTable !== '') {
                $sessionsTableExists = $sessionSchema->hasTable($sessionTable);

                if ($sessionsTableExists) {
                    $sessionTableColumnsValid = array_diff(
                        ['id', 'user_id', 'ip_address', 'user_agent', 'payload', 'last_activity'],
                        $sessionSchema->getColumnListing($sessionTable),
                    ) === [];
                }
            }
        } catch (Throwable $exception) {
            $databaseExceptionClass = $exception::class;
        }

        $hash = is_string($admin?->password) ? $admin->password : null;
        $hashAlgorithm = $hash !== null ? password_get_info($hash)['algoName'] : 'unknown';
        $hashValidFormat = $hashAlgorithm !== 'unknown';
        $hashSupported = false;

        if ($hashValidFormat) {
            try {
                Hash::check('diagnostic-nonmatching-value', $hash);
                $hashSupported = true;
            } catch (Throwable) {
                $hashSupported = false;
            }
        }

        $appKeyPresent = is_string(config('app.key')) && config('app.key') !== '';
        $appKeyValid = false;

        if ($appKeyPresent) {
            try {
                $appKeyValid = Crypt::decryptString(Crypt::encryptString('auth-diagnostic-probe')) === 'auth-diagnostic-probe';
            } catch (Throwable) {
                $appKeyValid = false;
            }
        }

        return $this->json([
            'user_model_loadable' => class_exists(User::class) && is_subclass_of(User::class, \Illuminate\Foundation\Auth\User::class),
            'users_table_exists' => $usersTableExists,
            'target_admin_exists' => $admin !== null,
            'password_present' => $hash !== null && $hash !== '',
            'password_hash_algorithm' => $hashAlgorithm,
            'password_hash_valid_format' => $hashValidFormat,
            'password_hash_supported' => $hashSupported,
            'auth_default_guard' => config('auth.defaults.guard'),
            'auth_provider_model' => config('auth.providers.users.model'),
            'session_driver' => config('session.driver'),
            'sessions_table_exists' => $sessionsTableExists,
            'session_table_columns_valid' => $sessionTableColumnsValid,
            'app_key_present' => $appKeyPresent,
            'app_key_valid' => $appKeyValid,
            'session_encryption_enabled' => (bool) config('session.encrypt'),
            'sanctum_loaded' => class_exists(Sanctum::class),
            'database_exception_class' => $databaseExceptionClass,
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        if (($denied = $this->denyWithoutKey($request)) !== null) {
            return $denied;
        }

        $stage = 'validation';
        $user = null;

        try {
            $validator = Validator::make($request->only(['email', 'password']), (new LoginRequest)->rules());

            if ($validator->fails()) {
                return $this->json(['ok' => false, 'stage' => $stage, 'message' => 'Invalid credentials format.'], 422);
            }

            $credentials = $validator->validated();
            $stage = 'user_lookup';
            $guard = Auth::guard('web');
            $provider = $guard->getProvider();

            $user = $provider->retrieveByCredentials($credentials);

            if ($user === null) {
                return $this->invalidCredentials($stage);
            }

            $stage = 'password_check';

            if (! $provider->validateCredentials($user, $credentials)) {
                return $this->invalidCredentials($stage);
            }

            $stage = 'auth_attempt';

            if (! $guard->attempt($credentials)) {
                return $this->invalidCredentials($stage);
            }

            $user = $guard->user();
            $stage = 'account_policy';

            if ($user instanceof User && $user->isDoctor()
                && ($user->must_change_password || $user->doctorProfile?->status !== Doctor::STATUS_ACTIVE)) {
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return $this->json(['ok' => false, 'stage' => $stage, 'message' => 'Account is not eligible to log in.'], 403);
            }

            $stage = 'session_regenerate';
            $request->session()->regenerate();

            $stage = 'user_response';
            (new UserResource($guard->user()))->additional(['message' => 'Login successful.'])->response();

            $stage = 'session_save';
            $request->session()->save();

            return $this->json(['ok' => true, 'stage' => 'completed']);
        } catch (Throwable $exception) {
            return $this->json([
                'ok' => false,
                'stage' => $stage,
                'exception_class' => $exception::class,
                'message' => $this->safeExceptionMessage($exception, $request, $user),
                'file' => basename($exception->getFile()),
                'line' => $exception->getLine(),
            ], 500);
        }
    }

    private function denyWithoutKey(Request $request): ?JsonResponse
    {
        $key = config('storage_diagnostics.key');

        if (! is_string($key) || $key === '') {
            return $this->json(['message' => 'Not Found'], 404);
        }

        $providedKey = $request->header('X-Diagnostic-Key');

        if (! is_string($providedKey) || ! hash_equals($key, $providedKey)) {
            return $this->json(['message' => 'Forbidden'], 403);
        }

        return null;
    }

    private function invalidCredentials(string $stage): JsonResponse
    {
        return $this->json(['ok' => false, 'stage' => $stage, 'message' => 'Invalid credentials.'], 401);
    }

    private function safeExceptionMessage(Throwable $exception, Request $request, mixed $user): string
    {
        $message = $exception instanceof QueryException
            ? ($exception->getPrevious()?->getMessage() ?? 'Database query failed.')
            : $exception->getMessage();

        $sensitiveValues = [
            $request->input('password'),
            $request->header('X-Diagnostic-Key'),
            $request->header('Authorization'),
            $request->header('Cookie'),
            $request->header('X-XSRF-TOKEN'),
            $request->header('X-CSRF-TOKEN'),
            config('app.key'),
            $request->hasSession() ? $request->session()->getId() : null,
            $user instanceof User ? $user->getAuthPassword() : null,
            ...array_values($request->cookies->all()),
        ];

        foreach ($sensitiveValues as $value) {
            if (is_string($value) && $value !== '') {
                $message = str_replace([$value, rawurlencode($value)], '[redacted]', $message);
            }
        }

        $message = preg_replace('/\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\$argon2(?:id|i)\$[^\s,)]+/', '[redacted]', $message) ?? $message;
        $message = preg_replace('/\b(password|authorization|cookie|session[_ -]?id|csrf|xsrf|secret|app[_ -]?key)\s*[:=]\s*[^,\s)]+/i', '$1=[redacted]', $message) ?? $message;
        $message = str_replace(base_path(), '[app]', $message);

        return substr($message, 0, 500);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status)->header('Cache-Control', 'no-store');
    }
}
