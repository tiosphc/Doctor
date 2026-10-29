<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'phone', 'password', 'must_change_password', 'role', 'email_verified_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const ROLE_ADMIN = 'admin';

    public const ROLE_CUSTOMER = 'customer';

    public const ROLE_RECEPTIONIST = 'receptionist';

    public const ROLE_DOCTOR = 'doctor';

    public const STAFF_ROLES = [self::ROLE_RECEPTIONIST, self::ROLE_DOCTOR];

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_CUSTOMER, ...self::STAFF_ROLES];

    public function isAdmin(): bool
    {
        return $this->role === self::ROLE_ADMIN;
    }

    public function isCustomer(): bool
    {
        return $this->role === self::ROLE_CUSTOMER;
    }

    public function isReceptionist(): bool
    {
        return $this->role === self::ROLE_RECEPTIONIST;
    }

    public function isDoctor(): bool
    {
        return $this->role === self::ROLE_DOCTOR;
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function customer(): HasOne
    {
        return $this->hasOne(Customer::class);
    }

    public function dealerApplications(): HasMany
    {
        return $this->hasMany(DealerApplication::class);
    }

    public function dealerMemberships(): HasMany
    {
        return $this->hasMany(DealerAccountUser::class);
    }

    public function blogs(): HasMany
    {
        return $this->hasMany(Blog::class, 'author_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(Voucher::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class, 'actor_id');
    }

    public function doctorProfile(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'must_change_password' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
