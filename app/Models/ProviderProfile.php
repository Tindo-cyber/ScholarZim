<?php

namespace App\Models;

use App\Support\ProviderOrgType;
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
        'registration_number',
        'certificate_path',
        'certificate_filename',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function organisationTypeLabel(): string
    {
        return ProviderOrgType::label($this->organisation_type);
    }

    public function isReviewed(): bool
    {
        return $this->reviewed_at !== null;
    }
}
