<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Ported from com.scholarzim.entity.User.
 *
 * The column names come straight from the original schema, so the same MySQL
 * database can back either application: the primary key is user_id and the
 * password lives in password_hash rather than Laravel's default password.
 */
class User extends Authenticatable
{
    use HasFactory;
    use Notifiable;

    protected $table = 'users';

    protected $primaryKey = 'user_id';

    protected $fillable = [
        'role_id',
        'full_name',
        'email',
        'phone',
        'password_hash',
        'account_status',
        'email_verified',
        'is_super_admin',
        'email_notify_applications',
        'email_notify_scholarships',
        'email_notify_system',
    ];

    protected $hidden = [
        'password_hash',
        'remember_token',
        // Columns left behind by the removed two-factor feature. Hidden rather
        // than dropped so a legacy row can never be serialised into a response.
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected $casts = [
        'email_verified' => 'boolean',
        'is_super_admin' => 'boolean',
        'email_notify_applications' => 'boolean',
        'email_notify_scholarships' => 'boolean',
        'email_notify_system' => 'boolean',
    ];

    /** Laravel hashes into "password"; the schema stores it in password_hash. */
    public function getAuthPassword(): ?string
    {
        return $this->password_hash;
    }

    public function setPasswordAttribute(string $value): void
    {
        $this->attributes['password_hash'] = $value;
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id', 'role_id');
    }

    public function applicantProfile(): HasOne
    {
        return $this->hasOne(ApplicantProfile::class, 'user_id', 'user_id');
    }

    public function providerProfile(): HasOne
    {
        return $this->hasOne(ProviderProfile::class, 'user_id', 'user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'user_id', 'user_id');
    }

    public function opportunities(): HasMany
    {
        return $this->hasMany(Opportunity::class, 'provider_user_id', 'user_id');
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(Notification::class, 'user_id', 'user_id');
    }

    public function savedScholarships(): HasMany
    {
        return $this->hasMany(SavedScholarship::class, 'user_id', 'user_id');
    }

    public function roleName(): string
    {
        return $this->role?->role_name ?? '';
    }

    public function hasRole(string $roleName): bool
    {
        return $this->roleName() === $roleName;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(\App\Support\RoleNames::ADMIN);
    }

    public function isProvider(): bool
    {
        return $this->hasRole(\App\Support\RoleNames::PROVIDER);
    }

    public function isApplicant(): bool
    {
        return $this->hasRole(\App\Support\RoleNames::APPLICANT);
    }

    public function isActive(): bool
    {
        return $this->account_status === null
            || strcasecmp($this->account_status, \App\Support\AccountStatus::ACTIVE) === 0;
    }

    public function displayName(): string
    {
        return $this->full_name ?: $this->email ?: 'User';
    }

    public function initials(): string
    {
        $source = trim((string) ($this->full_name ?: $this->email));
        if ($source === '') {
            return 'SZ';
        }

        $parts = preg_split('/\s+/', $source) ?: [];
        if (count($parts) >= 2) {
            return strtoupper(mb_substr($parts[0], 0, 1) . mb_substr($parts[1], 0, 1));
        }

        return strtoupper(mb_substr($source, 0, 2));
    }
}
