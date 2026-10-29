<?php

namespace App\Services;

use App\Models\DealerAccount;
use App\Models\DealerAccountUser;
use App\Models\DealerApplication;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DealerApplicationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DealerTierService $tiers,
        private readonly DealerWalletService $wallets,
    ) {}

    /** @param array<string, mixed> $data */
    public function submit(User $applicant, array $data): DealerApplication
    {
        return DB::transaction(function () use ($applicant, $data): DealerApplication {
            $user = User::query()->lockForUpdate()->findOrFail($applicant->id);
            if (! $user->isCustomer()) {
                $this->conflict('DEALER_APPLICANT_INELIGIBLE');
            }
            if ($user->dealerApplications()->where('status', DealerApplication::STATUS_PENDING)->exists()) {
                $this->conflict('DEALER_APPLICATION_PENDING');
            }
            if ($user->dealerMemberships()->where('status', DealerAccountUser::STATUS_ACTIVE)->exists()) {
                $this->conflict('DEALER_MEMBERSHIP_EXISTS');
            }
            $application = $user->dealerApplications()->create([...$data, 'status' => DealerApplication::STATUS_PENDING]);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_DEALER_APPLICATION, $application, 'Dealer Application submitted', metadata: ['applicant_id' => $user->id]);

            return $application;
        }, 3);
    }

    public function approve(DealerApplication $application, User $reviewer): DealerApplication
    {
        return DB::transaction(function () use ($application, $reviewer): DealerApplication {
            $locked = DealerApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->status === DealerApplication::STATUS_APPROVED) {
                $approvedAccount = $locked->approvedAccount()->first();
                if ($approvedAccount === null) {
                    $this->conflict('DEALER_APPROVAL_INCOMPLETE');
                }
                $this->wallets->ensure($approvedAccount);

                return $locked->load('approvedAccount');
            }
            if ($locked->status !== DealerApplication::STATUS_PENDING) {
                $this->conflict('DEALER_APPLICATION_NOT_PENDING');
            }
            $applicant = User::query()->lockForUpdate()->findOrFail($locked->user_id);
            if (! $applicant->isCustomer()) {
                $this->conflict('DEALER_APPLICANT_INELIGIBLE');
            }
            if ($applicant->dealerMemberships()->where('status', DealerAccountUser::STATUS_ACTIVE)->exists()) {
                $this->conflict('DEALER_MEMBERSHIP_EXISTS');
            }
            $this->tiers->defaultInitial();
            $account = DealerAccount::create([
                'code' => 'TMP-'.Str::upper(Str::random(20)),
                'legal_name' => $locked->company_name,
                'trading_name' => $locked->trading_name,
                'contact_name' => $locked->contact_name,
                'email' => $locked->email,
                'phone' => $locked->phone,
                'tax_code' => $locked->tax_code,
                'billing_address_line1' => $locked->business_address_line1,
                'billing_address_line2' => $locked->business_address_line2,
                'city' => $locked->city,
                'province' => $locked->province,
                'country' => $locked->country,
                'postal_code' => $locked->postal_code,
                'status' => DealerAccount::STATUS_ACTIVE,
                'source_application_id' => $locked->id,
                'created_by' => $reviewer->id,
                'activated_at' => now(),
            ]);
            $account->update(['code' => 'DLR'.str_pad((string) $account->id, 8, '0', STR_PAD_LEFT)]);
            $membership = $account->memberships()->create([
                'user_id' => $applicant->id,
                'membership_role' => DealerAccountUser::ROLE_OWNER,
                'status' => DealerAccountUser::STATUS_ACTIVE,
                'activated_at' => now(),
            ]);
            $this->tiers->assignInitial($account, $reviewer);
            $this->wallets->ensure($account);
            $locked->update(['status' => DealerApplication::STATUS_APPROVED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now()]);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_DEALER_ACCOUNT, $account, 'Dealer Account created', metadata: ['application_id' => $locked->id]);
            $this->audit->log(AuditLogger::ACTION_CREATE, AuditLogger::MODULE_DEALER_MEMBERSHIP, $membership, 'Dealer owner membership created', metadata: ['dealer_account_id' => $account->id, 'user_id' => $applicant->id]);
            $this->audit->log(AuditLogger::ACTION_CONFIRM, AuditLogger::MODULE_DEALER_APPLICATION, $locked, 'Dealer Application approved', metadata: ['dealer_account_id' => $account->id]);

            return $locked->load('approvedAccount');
        }, 3);
    }

    public function reject(DealerApplication $application, User $reviewer, string $reason): DealerApplication
    {
        return DB::transaction(function () use ($application, $reviewer, $reason): DealerApplication {
            $locked = DealerApplication::query()->lockForUpdate()->findOrFail($application->id);
            if ($locked->status !== DealerApplication::STATUS_PENDING) {
                $this->conflict('DEALER_APPLICATION_NOT_PENDING');
            }
            $locked->update(['status' => DealerApplication::STATUS_REJECTED, 'reviewed_by' => $reviewer->id, 'reviewed_at' => now(), 'rejection_reason' => trim($reason)]);
            $this->audit->log(AuditLogger::ACTION_DEACTIVATE, AuditLogger::MODULE_DEALER_APPLICATION, $locked, 'Dealer Application rejected', metadata: ['application_id' => $locked->id]);

            return $locked;
        }, 3);
    }

    private function conflict(string $code): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $code], 409));
    }
}
