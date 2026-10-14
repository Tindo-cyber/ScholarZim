<?php

namespace App\Models;

use App\Support\Countries;
use App\Support\ProviderOrgType;
use App\Support\ProviderVerificationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderProfile extends Model
{
    protected $table = 'provider_profiles';

    protected $primaryKey = 'profile_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'organisation_type',
        'certificate_path',
        'certificate_filename',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
        'trusted_override',
        'website',
        'website_verified_at',
        'website_verified_by',
        'registration_country',
        'registration_number',
        'registration_number_note',
        'contact_name',
        'contact_role',
        'info_requested_at',
        'info_requested_by',
        'info_request_note',
        'info_provided_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'trusted_override' => 'boolean',
        'website_verified_at' => 'datetime',
        'info_requested_at' => 'datetime',
        'info_provided_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function organisationTypeLabel(): string
    {
        return ProviderOrgType::label($this->organisation_type);
    }

    public function hasConfirmedWebsite(): bool
    {
        return filled($this->website) && $this->website_verified_at !== null;
    }

    /** The host of the website an administrator has confirmed, or null when there is none to rely on. */
    public function confirmedWebsiteHost(): ?string
    {
        return $this->hasConfirmedWebsite() ? \App\Support\WebsiteAddress::host($this->website) : null;
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }

    /**
     * The name of the country the organisation is registered in, or null when it
     * was never recorded (accounts from before the question was asked). Never
     * defaulted to Zimbabwe: an unknown country is shown as unknown.
     */
    public function registrationCountryName(): ?string
    {
        return Countries::name($this->registration_country);
    }

    public function hasRegistrationCountry(): bool
    {
        return $this->registrationCountryName() !== null;
    }

    /** An administrator asked for more evidence and the organisation has not answered since. */
    public function isAwaitingInformation(): bool
    {
        return $this->reviewed_at === null
            && $this->info_requested_at !== null
            && ($this->info_provided_at === null || $this->info_provided_at->lt($this->info_requested_at));
    }

    /** See ProviderVerificationStatus. */
    public function verificationStatus(): string
    {
        return ProviderVerificationStatus::of($this);
    }
}
