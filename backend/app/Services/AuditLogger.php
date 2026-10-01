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

    public const ACTION_OPENING_STOCK = 'OPENING_BALANCE';

    public const ACTION_GOODS_RECEIPT = 'GOODS_RECEIPT';

    public const ACTION_PURCHASE_RETURN = 'PURCHASE_RETURN';

    public const ACTION_ADJUSTMENT_IN = 'ADJUSTMENT_IN';

    public const ACTION_ADJUSTMENT_OUT = 'ADJUSTMENT_OUT';

    public const ACTION_REPRICE = 'REPRICE';

    public const ACTION_RESERVE = 'RESERVE';

    public const ACTION_RELEASE = 'RELEASE';

    public const ACTION_CONSUME = 'CONSUME';

    public const ACTION_FULFILL = 'FULFILL';

    public const ACTION_SALES_ORDER_SHIPMENT = 'SALES_ORDER_SHIPMENT';

    public const ACTION_PAYMENT_RECORDED = 'PAYMENT_RECORDED';

    public const ACTION_REFUND_COMPLETED = 'REFUND_COMPLETED';

    public const ACTION_RETURN_COMPLETED = 'RETURN_COMPLETED';

    public const ACTION_RETURN_RECEIVED = 'RETURN_RECEIVED';

    public const ACTION_RETURN_REQUESTED = 'RETURN_REQUESTED';

    public const ACTION_RETURN_APPROVED = 'RETURN_APPROVED';

    public const ACTION_RETURN_REJECTED = 'RETURN_REJECTED';

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
        self::ACTION_OPENING_STOCK,
        self::ACTION_GOODS_RECEIPT,
        self::ACTION_PURCHASE_RETURN,
        self::ACTION_ADJUSTMENT_IN,
        self::ACTION_ADJUSTMENT_OUT,
        self::ACTION_REPRICE,
        self::ACTION_RESERVE,
        self::ACTION_RELEASE,
        self::ACTION_CONSUME,
        self::ACTION_FULFILL,
        self::ACTION_SALES_ORDER_SHIPMENT,
        self::ACTION_PAYMENT_RECORDED,
        self::ACTION_REFUND_COMPLETED,
        self::ACTION_RETURN_COMPLETED,
        self::ACTION_RETURN_RECEIVED,
        self::ACTION_RETURN_REQUESTED,
        self::ACTION_RETURN_APPROVED,
        self::ACTION_RETURN_REJECTED,
    ];

    public const MODULE_STAFF = 'STAFF';

    public const MODULE_DOCTOR = 'DOCTOR';

    public const MODULE_APPOINTMENT = 'APPOINTMENT';

    public const MODULE_SERVICE = 'SERVICE';

    public const MODULE_VOUCHER = 'VOUCHER';

    public const MODULE_REVIEW = 'REVIEW';

    public const MODULE_BLOG = 'BLOG';

    public const MODULE_WAREHOUSE = 'WAREHOUSE';

    public const MODULE_INVENTORY = 'INVENTORY';

    public const MODULE_PROCUREMENT = 'PROCUREMENT';

    public const MODULE_PRODUCT = 'PRODUCT';

    public const MODULE_SALES_ORDER = 'SALES_ORDER';

    public const MODULE_DEALER_APPLICATION = 'DEALER_APPLICATION';

    public const MODULE_DEALER_ACCOUNT = 'DEALER_ACCOUNT';

    public const MODULE_DEALER_MEMBERSHIP = 'DEALER_MEMBERSHIP';

    public const MODULE_DEALER_TIER = 'DEALER_TIER';

    public const MODULE_DEALER_ORDER_IMPORT = 'DEALER_ORDER_IMPORT';

    public const MODULE_PAYMENT = 'PAYMENT';

    public const MODULE_REFUND = 'REFUND';

    public const MODULE_RETURN = 'SALES_RETURN';

    public const MODULE_SALES_PROMOTION = 'SALES_PROMOTION';

    public const MODULES = [
        self::MODULE_STAFF,
        self::MODULE_DOCTOR,
        self::MODULE_APPOINTMENT,
        self::MODULE_SERVICE,
        self::MODULE_VOUCHER,
        self::MODULE_REVIEW,
        self::MODULE_BLOG,
        self::MODULE_WAREHOUSE,
        self::MODULE_INVENTORY,
        self::MODULE_PROCUREMENT,
        self::MODULE_PRODUCT,
        self::MODULE_SALES_ORDER,
        self::MODULE_DEALER_APPLICATION,
        self::MODULE_DEALER_ACCOUNT,
        self::MODULE_DEALER_MEMBERSHIP,
        self::MODULE_DEALER_TIER,
        self::MODULE_DEALER_ORDER_IMPORT,
        self::MODULE_PAYMENT,
        self::MODULE_REFUND,
        self::MODULE_RETURN,
        self::MODULE_SALES_PROMOTION,
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
