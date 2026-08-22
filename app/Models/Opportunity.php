<?php

namespace App\Models;

use App\Support\OpportunityModerationStatus;
use App\Support\OpportunityStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class Opportunity extends Model
{
    protected $table = 'opportunities';

    protected $primaryKey = 'opportunity_id';

    /** created_at is written explicitly, and the table has no updated_at. */
    public $timestamps = false;

    protected $fillable = [
        'provider_user_id',
        'title',
        'description',
        'provider_name',
        'education_level',
        'funding_type',
        'country',
        'deadline',
        'status',
        'created_at',
        'target_field',
        'target_country',
        'moderation_status',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'rejection_reason',
    ];

    protected $casts = [
        'deadline' => 'date',
        'created_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
    ];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'provider_user_id', 'user_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(Application::class, 'opportunity_id', 'opportunity_id');
    }

    public function savedBy(): HasMany
    {
        return $this->hasMany(SavedScholarship::class, 'opportunity_id', 'opportunity_id');
    }

    /**
     * Everything the public site is allowed to show: open listing AND cleared
     * moderation AND not past its deadline.
     */
    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query
            ->where('status', OpportunityStatus::ACTIVE)
            ->where('moderation_status', OpportunityModerationStatus::APPROVED)
            ->where(function (Builder $q) {
                $q->whereNull('deadline')->orWhere('deadline', '>=', Carbon::today()->toDateString());
            });
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('moderation_status', OpportunityModerationStatus::APPROVED);
    }

    /**
     * Faceted search, ported from OpportunityRepository.searchWithKeyword.
     * Blank facets are ignored rather than matched against an empty string.
     */
    public function scopeMatchingFilters(Builder $query, array $filters): Builder
    {
        $value = static fn (?string $key) => ($key !== null && trim($key) !== '') ? trim($key) : null;

        if ($level = $value($filters['education_level'] ?? null)) {
            $query->where('education_level', $level);
        }

        if ($country = $value($filters['country'] ?? null)) {
            $query->where(function (Builder $q) use ($country) {
                $q->where('country', $country)->orWhere('target_country', $country);
            });
        }

        if ($field = $value($filters['field_of_study'] ?? null)) {
            $query->where('target_field', $field);
        }

        if ($provider = $value($filters['provider'] ?? null)) {
            $query->where('provider_name', $provider);
        }

        if ($funding = $value($filters['funding_type'] ?? null)) {
            $query->where('funding_type', $funding);
        }

        if (! empty($filters['deadline_before'])) {
            $query->whereDate('deadline', '<=', $filters['deadline_before']);
        }

        if ($keyword = $value($filters['keyword'] ?? null)) {
            $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $keyword) . '%';
            $query->where(function (Builder $q) use ($like) {
                $q->where('title', 'like', $like)
                    ->orWhere('description', 'like', $like)
                    ->orWhere('provider_name', 'like', $like);
            });
        }

        return $query;
    }

    public function statusLabel(): string
    {
        return OpportunityStatus::displayLabel($this->status);
    }

    public function moderationLabel(): string
    {
        return OpportunityModerationStatus::displayLabel($this->moderation_status);
    }

    public function moderationTone(): string
    {
        return OpportunityModerationStatus::badgeTone($this->moderation_status);
    }

    public function isPubliclyVisible(): bool
    {
        return OpportunityModerationStatus::isApproved($this->moderation_status);
    }

    public function daysUntilDeadline(): ?int
    {
        if (! $this->deadline) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->deadline, false);
    }

    public function isClosingSoon(): bool
    {
        $days = $this->daysUntilDeadline();

        return $days !== null && $days >= 0 && $days <= 14;
    }

    public function isExpired(): bool
    {
        $days = $this->daysUntilDeadline();

        return $days !== null && $days < 0;
    }

    public function awardingBody(): string
    {
        return $this->provider_name ?: ($this->provider?->full_name ?? 'Unnamed provider');
    }
}
