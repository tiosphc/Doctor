<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public const ACTION_CREATE = 'CREATE';

    public const ACTION_UPDATE = 'UPDATE';

    public const ACTION_DELETE = 'DELETE';

    public const ACTION_ACTIVATE = 'ACTIVATE';

    public const ACTION_DEACTIVATE = 'DEACTIVATE';

    public const ACTION_CONFIRM = 'CONFIRM';

    public const ACTION_CHECK_IN = 'CHECK_IN';

    public const ACTION_START_EXAMINATION = 'START_EXAMINATION';

    public const ACTION_FINISH_EXAMINATION = 'FINISH_EXAMINATION';

    public const ACTION_COMPLETE = 'COMPLETE';

    public const ACTION_CANCEL = 'CANCEL';

    public const ACTION_NO_SHOW = 'NO_SHOW';

    public const ACTION_RESCHEDULE = 'RESCHEDULE';

    public const ACTIONS = [
        self::ACTION_CREATE,
        self::ACTION_UPDATE,
        self::ACTION_DELETE,
        self::ACTION_ACTIVATE,
        self::ACTION_DEACTIVATE,
        self::ACTION_CONFIRM,
        self::ACTION_CHECK_IN,
        self::ACTION_START_EXAMINATION,
        self::ACTION_FINISH_EXAMINATION,
        self::ACTION_COMPLETE,
        self::ACTION_CANCEL,
        self::ACTION_NO_SHOW,
        self::ACTION_RESCHEDULE,
    ];

    public const MODULE_STAFF = 'STAFF';

    public const MODULE_DOCTOR = 'DOCTOR';

    public const MODULE_APPOINTMENT = 'APPOINTMENT';

    public const MODULE_SERVICE = 'SERVICE';

    public const MODULE_VOUCHER = 'VOUCHER';

    public const MODULE_REVIEW = 'REVIEW';

    public const MODULE_BLOG = 'BLOG';

    public const MODULES = [
        self::MODULE_STAFF,
        self::MODULE_DOCTOR,
        self::MODULE_APPOINTMENT,
        self::MODULE_SERVICE,
        self::MODULE_VOUCHER,
        self::MODULE_REVIEW,
        self::MODULE_BLOG,
    ];

    /** @var list<string> */
    private const OMITTED_FIELDS = [
        'created_at', 'updated_at', 'deleted_at', 'password', 'password_confirmation',
        'current_password', 'new_password', 'remember_token', 'access_token', 'refresh_token',
        'api_token', 'reset_token', 'verification_token', 'secret', 'client_secret',
        'authorization', 'otp', 'api_key',
    ];

    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function log(
        string $action,
        string $module,
        Model $target,
        string $description,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        ?string $targetName = null,
        ?string $actorName = null,
        ?string $actorRole = null,
    ): AuditLog {
        $actor = $this->request->user();
        $safeOldValues = $this->sanitize($oldValues);
        $safeNewValues = $this->sanitize($newValues);
        $safeMetadata = $this->sanitize($metadata);

        return AuditLog::create([
            'actor_id' => $actor instanceof User ? $actor->id : null,
            'actor_name' => $actor instanceof User ? $actor->name : ($actorName ?? 'Khách'),
            'actor_role' => $actor instanceof User ? $actor->role : ($actorRole ?? 'guest'),
            'action' => $action,
            'module' => $module,
            'target_type' => class_basename($target),
            'target_id' => $target->getKey(),
            'target_name' => $targetName ?? $this->targetName($target),
            'description' => $description,
            'old_values' => $safeOldValues === [] ? null : $safeOldValues,
            'new_values' => $safeNewValues === [] ? null : $safeNewValues,
            'metadata' => $safeMetadata === [] ? null : $safeMetadata,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'request_method' => $this->request->method(),
            'request_url' => $this->requestUrl(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{old: array<string, mixed>, new: array<string, mixed>}
     */
    public function diff(array $before, array $after): array
    {
        $before = $this->sanitize($before);
        $after = $this->sanitize($after);
        $keys = array_unique([...array_keys($before), ...array_keys($after)]);
        $old = [];
        $new = [];

        foreach ($keys as $key) {
            $oldValue = $before[$key] ?? null;
            $newValue = $after[$key] ?? null;

            if ($oldValue === $newValue) {
                continue;
            }

            $old[$key] = $oldValue;
            $new[$key] = $newValue;
        }

        return ['old' => $old, 'new' => $new];
    }

    /** @param array<string, mixed> $values @return array<string, mixed> */
    public function sanitize(array $values): array
    {
        $safe = [];

        foreach ($values as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);

            if ($this->isSensitiveKey($normalizedKey)) {
                continue;
            }

            $safe[$key] = is_array($value) ? $this->sanitize($value) : $this->normalizeValue($value);
        }

        return $safe;
    }

    private function isSensitiveKey(string $key): bool
    {
        return in_array($key, self::OMITTED_FIELDS, true)
            || str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'secret')
            || str_contains($key, 'authorization')
            || str_contains($key, 'otp')
            || str_contains($key, 'api_key');
    }

    private function targetName(Model $target): string
    {
        foreach (['name', 'title', 'booking_code', 'code', 'email'] as $attribute) {
            $value = $target->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return class_basename($target).' #'.$target->getKey();
    }

    private function normalizeValue(mixed $value): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(DATE_ATOM) : $value;
    }

    private function requestUrl(): string
    {
        $query = $this->sanitize($this->request->query());

        return $query === []
            ? $this->request->url()
            : $this->request->url().'?'.http_build_query($query);
    }
}
