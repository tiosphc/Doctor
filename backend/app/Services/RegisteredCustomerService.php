<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Support\CustomerCode;
use App\Support\CustomerIdentityNormalizer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class RegisteredCustomerService
{
    public const DECISION_AUTO_MATCH = 'AUTO_MATCH';

    public const DECISION_CREATE_NEW = 'CREATE_NEW';

    public const DECISION_REQUIRES_REVIEW = 'REQUIRES_REVIEW';

    public function __construct(private readonly CustomerIdentityNormalizer $normalizer) {}

    public function ensureForUser(User $user): RegisteredCustomerResult
    {
        return DB::transaction(function () use ($user): RegisteredCustomerResult {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $this->ensureForLockedUser($lockedUser);
        }, 3);
    }

    public function synchronizeFromUser(User $user): RegisteredCustomerResult
    {
        return DB::transaction(function () use ($user): RegisteredCustomerResult {
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $result = $this->ensureForLockedUser($lockedUser);
            $identity = $this->identityFor($lockedUser);

            if ($identity['name'] === null) {
                throw new RuntimeException('A registered Customer requires a non-blank name.');
            }

            $result->customer->forceFill([
                'name' => $identity['name'],
                'primary_email' => $identity['primary_email'],
                'normalized_email' => $identity['normalized_email'],
                'primary_phone' => $identity['primary_phone'],
                'normalized_phone' => $identity['normalized_phone'],
                'verified_email_at' => $identity['normalized_email'] === null
                    ? null
                    : $lockedUser->email_verified_at,
            ])->save();

            $inspection = $this->inspect($lockedUser, $result->customer);
            $conflictId = $this->persistInspectionConflict($lockedUser, $inspection);

            return new RegisteredCustomerResult(
                $result->customer->refresh(),
                $conflictId === null ? $result->decision : self::DECISION_REQUIRES_REVIEW,
                $conflictId,
                $result->created,
                $inspection['invalid_data'],
            );
        }, 3);
    }

    public function ensureForLockedUser(User $user): RegisteredCustomerResult
    {
        if (! $user->isCustomer()) {
            throw new InvalidArgumentException('Only users with the customer role may receive a canonical Customer.');
        }

        $customer = Customer::query()
            ->where('user_id', $user->id)
            ->lockForUpdate()
            ->first();
        $created = false;

        if ($customer === null) {
            $identity = $this->identityFor($user);

            if ($identity['name'] === null) {
                throw new RuntimeException('A registered Customer requires a non-blank name.');
            }

            $customer = Customer::query()->create([
                'customer_code' => null,
                'user_id' => $user->id,
                'name' => $identity['name'],
                'primary_email' => $identity['primary_email'],
                'normalized_email' => $identity['normalized_email'],
                'primary_phone' => $identity['primary_phone'],
                'normalized_phone' => $identity['normalized_phone'],
                'verified_email_at' => $identity['normalized_email'] === null
                    ? null
                    : $user->email_verified_at,
                'verified_phone_at' => null,
                'status' => Customer::STATUS_ACTIVE,
                'source' => Customer::SOURCE_REGISTERED,
                'merged_into_customer_id' => null,
            ]);
            $created = true;
        }

        if ($customer->customer_code === null) {
            $customer->forceFill([
                'customer_code' => CustomerCode::fromId($customer->id),
            ])->save();
        }

        $inspection = $this->inspect($user, $customer);
        $conflictId = $this->persistInspectionConflict($user, $inspection);
        $decision = $created ? self::DECISION_CREATE_NEW : self::DECISION_AUTO_MATCH;

        if ($conflictId !== null) {
            $decision = self::DECISION_REQUIRES_REVIEW;
        }

        return new RegisteredCustomerResult(
            $customer->refresh(),
            $decision,
            $conflictId,
            $created,
            $inspection['invalid_data'],
        );
    }

    /**
     * @return array{
     *     identity: array{name: ?string, primary_email: ?string, normalized_email: ?string, primary_phone: ?string, normalized_phone: ?string},
     *     candidates: list<array{customer_id: int, match_basis: string, confidence: string}>,
     *     invalid_data: bool
     * }
     */
    public function inspect(User $user, ?Customer $ownCustomer = null): array
    {
        $identity = $this->identityFor($user);
        $invalidData = $identity['name'] === null
            || ($identity['primary_email'] !== null && $identity['normalized_email'] === null)
            || ($identity['primary_phone'] !== null && $identity['normalized_phone'] === null);
        $candidates = $this->matchingCandidates(
            $identity['normalized_email'],
            $identity['normalized_phone'],
            $ownCustomer?->id,
            $user->email_verified_at !== null,
        );

        return [
            'identity' => $identity,
            'candidates' => $candidates,
            'invalid_data' => $invalidData,
        ];
    }

    public function inputFingerprint(User $user): string
    {
        $identity = $this->identityFor($user);

        return hash('sha256', json_encode([
            'version' => CustomerIdentityNormalizer::VERSION,
            'user_id' => $user->id,
            'name' => $identity['name'],
            'primary_email' => $identity['primary_email'],
            'normalized_email' => $identity['normalized_email'],
            'primary_phone' => $identity['primary_phone'],
            'normalized_phone' => $identity['normalized_phone'],
            'email_verified_at' => $user->email_verified_at?->toISOString(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{customer_id: int, match_basis: string, confidence: string}>  $candidates
     */
    public function persistConflict(
        string $sourceType,
        int $sourceId,
        string $reasonCode,
        array $candidates = [],
    ): int {
        $candidateIds = collect($candidates)
            ->pluck('customer_id')
            ->unique()
            ->sort()
            ->values()
            ->all();
        $conflictKey = hash('sha256', implode('|', [
            CustomerIdentityNormalizer::VERSION,
            $sourceType,
            (string) $sourceId,
            $reasonCode,
            implode(',', $candidateIds),
        ]));
        $now = now();

        DB::table('customer_identity_conflicts')->insertOrIgnore([
            'conflict_key' => $conflictKey,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'reason_code' => $reasonCode,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $conflictId = (int) DB::table('customer_identity_conflicts')
            ->where('conflict_key', $conflictKey)
            ->value('id');

        foreach ($candidates as $candidate) {
            DB::table('customer_identity_conflict_candidates')->insertOrIgnore([
                'conflict_id' => $conflictId,
                'customer_id' => $candidate['customer_id'],
                'match_basis' => $candidate['match_basis'],
                'confidence' => $candidate['confidence'],
                'created_at' => $now,
            ]);
        }

        return $conflictId;
    }

    /**
     * @return array{name: ?string, primary_email: ?string, normalized_email: ?string, primary_phone: ?string, normalized_phone: ?string}
     */
    private function identityFor(User $user): array
    {
        return [
            'name' => $this->normalizer->normalizeName($user->name),
            'primary_email' => $this->normalizer->displayEmail($user->email),
            'normalized_email' => $this->normalizer->normalizeEmail($user->email),
            'primary_phone' => $this->normalizer->displayPhone($user->phone),
            'normalized_phone' => $this->normalizer->normalizePhone($user->phone),
        ];
    }

    /**
     * @return list<array{customer_id: int, match_basis: string, confidence: string}>
     */
    private function matchingCandidates(
        ?string $normalizedEmail,
        ?string $normalizedPhone,
        ?int $excludedCustomerId,
        bool $emailIsVerified,
    ): array {
        if ($normalizedEmail === null && $normalizedPhone === null) {
            return [];
        }

        /** @var Collection<int, Customer> $matches */
        $matches = Customer::query()
            ->when($excludedCustomerId !== null, fn ($query) => $query->whereKeyNot($excludedCustomerId))
            ->where(function ($query) use ($normalizedEmail, $normalizedPhone): void {
                if ($normalizedEmail !== null) {
                    $query->where('normalized_email', $normalizedEmail);
                }

                if ($normalizedPhone !== null) {
                    $method = $normalizedEmail === null ? 'where' : 'orWhere';
                    $query->{$method}('normalized_phone', $normalizedPhone);
                }
            })
            ->orderBy('id')
            ->get(['id', 'normalized_email', 'normalized_phone']);
        $candidates = [];

        foreach ($matches as $match) {
            if ($normalizedEmail !== null && $match->normalized_email === $normalizedEmail) {
                $candidates[] = [
                    'customer_id' => $match->id,
                    'match_basis' => 'email',
                    'confidence' => $emailIsVerified ? 'medium' : 'low',
                ];
            }

            if ($normalizedPhone !== null && $match->normalized_phone === $normalizedPhone) {
                $candidates[] = [
                    'customer_id' => $match->id,
                    'match_basis' => 'phone',
                    'confidence' => 'low',
                ];
            }
        }

        return $candidates;
    }

    /**
     * @param  array{candidates: list<array{customer_id: int, match_basis: string, confidence: string}>, invalid_data: bool}  $inspection
     */
    private function persistInspectionConflict(User $user, array $inspection): ?int
    {
        if (! $inspection['invalid_data'] && $inspection['candidates'] === []) {
            return null;
        }

        return $this->persistConflict(
            'user',
            $user->id,
            $inspection['invalid_data'] ? 'INVALID_REGISTERED_CONTACT' : 'REGISTERED_CONTACT_COLLISION',
            $inspection['candidates'],
        );
    }
}
