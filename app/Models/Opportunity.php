<?php

namespace App\Models;

use App\Support\OpportunityLifecycle;
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
        'updated_at',
        'last_change_reason',
        'award_amount',
        'award_currency',
        'award_slots',
        'is_renewable',
        'external_url',
        'min_academic_points',
        'max_age',
        'required_citizenship',
        'required_province',
        'target_district',
        'target_locality',
        'requires_results_certificate',
        'view_count',
    ];

    protected $casts = [
        'deadline' => 'date',
        'created_at' => 'datetime',
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'updated_at' => 'datetime',
        'award_amount' => 'decimal:2',
        'award_slots' => 'integer',
        'is_renewable' => 'boolean',
        'min_academic_points' => 'integer',
        'max_age' => 'integer',
        'requires_results_certificate' => 'boolean',
        'view_count' => 'integer',
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

    public function dailyViews(): HasMany
    {
        return $this->hasMany(OpportunityView::class, 'opportunity_id', 'opportunity_id');
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

        // A minimum-value filter has to exclude listings that state no value at
        // all: "at least USD 1,000" cannot honestly include an unknown amount.
        if (filled($filters['min_award'] ?? null) && is_numeric($filters['min_award'])) {
            $query->whereNotNull('award_amount')
                ->where('award_amount', '>=', (float) $filters['min_award']);
        }

        if (! empty($filters['renewable_only'])) {
            $query->where('is_renewable', true);
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

    /**
     * The one state to show a human, drawn from both axes.
     *
     * The two columns are now genuinely independent, which is right for storage
     * and wrong for a badge: a withdrawn listing still carries the approval it
     * had when it was taken down, so rendering the moderation column alone would
     * label it "Published". Publication wins wherever it has something final to
     * say, and moderation speaks for everything still in play.
     */
    public function lifecycleLabel(): string
    {
        if ($this->isWithdrawn() || $this->isClosed()) {
            return OpportunityStatus::displayLabel($this->status);
        }

        return OpportunityModerationStatus::displayLabel($this->moderation_status);
    }

    public function lifecycleTone(): string
    {
        if ($this->isWithdrawn() || $this->isClosed()) {
            return OpportunityStatus::badgeTone($this->status);
        }

        return OpportunityModerationStatus::badgeTone($this->moderation_status);
    }

    /**
     * Taken down by the provider. Reads the publication column, not the
     * moderation one - withdrawing is the provider's decision about their own
     * listing, and it no longer overwrites the administrator's verdict.
     */
    public function isWithdrawn(): bool
    {
        return OpportunityStatus::isWithdrawn($this->status);
    }

    public function isClosed(): bool
    {
        return OpportunityStatus::isClosed($this->status);
    }

    public function deadlineHasPassed(): bool
    {
        return OpportunityLifecycle::deadlineHasPassed($this);
    }

    /**
     * The in-memory twin of scopePubliclyVisible(), answered by the same rule so
     * a loaded model and a query can never disagree about the same row.
     */
    public function isPubliclyVisible(): bool
    {
        return OpportunityLifecycle::isPubliclyVisible($this);
    }

    /** Whether a new application may still be made against this listing. */
    public function acceptsApplications(): bool
    {
        return OpportunityLifecycle::acceptsApplications($this);
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

    /**
     * Result ordering for the browse pages.
     *
     * An unrecognised key falls back to the default rather than throwing: the
     * value arrives straight off the query string.
     *
     * Listings with no award amount sort last under both value orderings - a
     * missing figure is not the same as a zero-value award, and burying them at
     * the top of "lowest first" would be misleading.
     */
    public function scopeSorted(Builder $query, ?string $sort): Builder
    {
        return match ($sort) {
            'deadline' => $query
                ->orderByRaw('CASE WHEN deadline IS NULL THEN 1 ELSE 0 END, deadline ASC')
                ->orderByDesc('created_at'),
            'award_desc' => $query
                ->orderByRaw('CASE WHEN award_amount IS NULL THEN 1 ELSE 0 END, award_amount DESC')
                ->orderByDesc('created_at'),
            'award_asc' => $query
                ->orderByRaw('CASE WHEN award_amount IS NULL THEN 1 ELSE 0 END, award_amount ASC')
                ->orderByDesc('created_at'),
            'title' => $query->orderBy('title'),
            default => $query->orderByDesc('created_at'),
        };
    }

    public function hasAwardValue(): bool
    {
        return $this->award_amount !== null && (float) $this->award_amount > 0;
    }

    /** "USD 2,500" - currency first, the way Zimbabwean notices quote it. */
    public function formattedAward(): ?string
    {
        if (! $this->hasAwardValue()) {
            return null;
        }

        $currency = $this->award_currency ?: \App\Support\FormOptions::DEFAULT_CURRENCY;
        $amount = (float) $this->award_amount;

        // Whole amounts read better without the trailing ".00" on a card.
        $formatted = fmod($amount, 1.0) === 0.0
            ? number_format($amount, 0)
            : number_format($amount, 2);

        return $currency . ' ' . $formatted;
    }

    public function awardSummary(): string
    {
        $parts = array_filter([
            $this->formattedAward(),
            $this->funding_type,
        ]);

        if ($parts === []) {
            return 'Value not stated';
        }

        $summary = implode(' · ', $parts);

        return $this->is_renewable ? $summary . ' · Renewable' : $summary;
    }

    /** True if the provider set at least one hard eligibility rule. */
    public function hasEligibilityRules(): bool
    {
        return $this->min_academic_points !== null
            || $this->max_age !== null
            || filled($this->required_citizenship)
            || filled($this->required_province)
            || (bool) $this->requires_results_certificate;
    }

    /**
     * Urgency band for the deadline chip: null (no deadline / far off), then
     * warning inside a week and danger inside three days.
     */
    public function deadlineTone(): ?string
    {
        $days = $this->daysUntilDeadline();

        if ($days === null || $days < 0) {
            return $days === null ? null : 'secondary';
        }

        return match (true) {
            $days <= 3 => 'danger',
            $days <= 7 => 'warning',
            $days <= 14 => 'info',
            default => null,
        };
    }

    public function deadlineLabel(): ?string
    {
        $days = $this->daysUntilDeadline();

        if ($days === null) {
            return null;
        }

        return match (true) {
            $days < 0 => 'Closed',
            $days === 0 => 'Closes today',
            $days === 1 => 'Closes tomorrow',
            default => 'Closes in ' . $days . ' days',
        };
    }
}
