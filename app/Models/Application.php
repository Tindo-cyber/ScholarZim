<?php

namespace App\Models;

use App\Support\ApplicationStateMachine;
use App\Support\ApplicationStatus;
use App\Support\AuditAction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    protected $table = 'applications';

    protected $primaryKey = 'application_id';

    public $timestamps = false;

    /**
     * The simplified workflow writes only these.
     *
     * The table still carries columns from the old multi-stage lifecycle
     * (interview_at, the info-request pair, interview_reminded_at, awarded_at).
     * They are left in place so historical rows keep their data, but they are
     * deliberately not fillable: nothing in the current workflow may write them.
     */
    protected $fillable = [
        'user_id',
        'opportunity_id',
        'application_status',
        'submitted_at',
        'personal_statement',
        'document_filename',
        'document_path',
        'decision_reason',
        'decided_at',
        'withdrawn_at',
        'withdrawal_reason',
        // Set once, by ApplicationService::markViewedByProvider() only - not a
        // status, just "has a provider opened this yet". See the migration
        // that added it for why this does not touch application_status.
        'viewed_by_provider_at',
        'submitted_snapshot',
        // What the screens show while a reopened application waits for its new decision; the withdrawn decision itself
        // is kept in the audit log. Only applications reopened before reopening was removed carry it; cleared by decide() / re-applying.
        'reopened_at',
        'reopen_reason',
        'reopened_from',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'viewed_by_provider_at' => 'datetime',
        'reopened_at' => 'datetime',
        'submitted_snapshot' => 'array',
    ];

    public function documents(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(ApplicationDocument::class, 'application_id', 'application_id');
    }

    /**
     * A document this application was sent with - results certificate, transcript or Grade 7 slip - as it was when
     * it was submitted: its stored path and the name it was given, or null if there was none.
     *
     * An application made before submissions were recorded has no record, and falls back to what the applicant's
     * profile holds now: the best that can be said for it.
     *
     * @return array{path: string, filename: ?string}|null
     */
    /**
     * The applicant as the provider is shown them: the snapshot taken when the application was sent, or - for an
     * application made before snapshots existed - the same shape built from the live profile.
     *
     * @return array{profile: array<string, mixed>, minor: bool, guardian: ?array<string, mixed>, results: array<int, array<string, mixed>>}
     */
    public function submittedProfile(): array
    {
        if (is_array($this->submitted_snapshot)) {
            return $this->submitted_snapshot;
        }

        $profile = $this->user?->applicantProfile;

        return $profile
            ? \App\Services\ApplicationSnapshot::build($profile)
            : ['profile' => [], 'minor' => false, 'guardian' => null, 'results' => []];
    }

    /**
     * The tracker rail for this application: the same stages as ApplicationStatus::timeline() - so the detail
     * page and the tracker can never disagree - each with the date it was reached (null if it has not been).
     *
     * @return array<int, array{label: string, done: bool, tone?: string, date: ?string}>
     */
    public function trackerStages(): array
    {
        return array_map(function (array $stage) {
            $at = match ($stage['label']) {
                'Applied' => $this->submitted_at,
                'Under review', 'Reviewed' => $this->viewed_by_provider_at,
                'Accepted', 'Not successful', 'Decision' => $this->decided_at,
                'Withdrawn' => $this->withdrawn_at,
                default => null,
            };

            return $stage + ['date' => $stage['done'] ? $at?->format('d M Y') : null];
        }, ApplicationStatus::timeline($this->application_status, $this->viewed_by_provider_at));
    }

    /** The eligibility verdict saved when the application was sent; null for one made before that was kept. */
    public function submittedEligibility(): ?array
    {
        return is_array($this->submitted_snapshot) ? ($this->submitted_snapshot['eligibility'] ?? null) : null;
    }

    /** The education level the applicant was at when this was sent; null for one made before the snapshot was kept. */
    public function submittedLevel(): ?string
    {
        $level = is_array($this->submitted_snapshot) ? ($this->submitted_snapshot['profile']['education_level'] ?? null) : null;

        return filled($level) ? (string) $level : null;
    }

    public function documentFor(string $type): ?array
    {
        $recorded = $this->relationLoaded('documents')
            ? $this->documents->firstWhere('type', $type)
            : $this->documents()->where('type', $type)->first();

        if ($recorded !== null) {
            return ['path' => $recorded->path, 'filename' => $recorded->filename];
        }

        // Any record at all means it was taken, and this type simply was not there.
        if ($this->documents()->exists() || ! in_array($type, ApplicationDocument::PROFILE_FALLBACK_TYPES, true)) {
            return null;
        }

        $profile = $this->user?->applicantProfile;
        [$path, $filename, $uploadedAt] = ApplicationDocument::TYPES[$type];

        if ($profile === null || blank($profile->{$path})) {
            return null;
        }

        // An application made before documents were recorded has only the applicant's profile to go on. A file put on
        // the profile AFTER the application was sent was not part of it, and is not shown as though it were. (A file
        // with no upload time predates the time being kept, so nothing says it came later.)
        $at = $profile->{$uploadedAt};

        if ($at !== null && $this->submitted_at !== null && \Illuminate\Support\Carbon::parse($at)->gt($this->submitted_at)) {
            return null;
        }

        return ['path' => $profile->{$path}, 'filename' => $profile->{$filename}];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }

    /**
     * The one answer to "does this application stop the applicant applying to
     * that opportunity again?" - and therefore to "does it stop the opportunity
     * being recommended to them?", which is the same question.
     *
     * It is a scope rather than a list of statuses copied into each caller
     * because it had been answered two different ways: ApplicationService asked
     * the state machine, while RecommendationService counted every application
     * regardless of status.
     *
     * A NULL status blocks. The state machine reads an unset status as pending,
     * and `NULL NOT IN (...)` is NULL rather than true in SQL, so saying so
     * explicitly is what keeps the query and the state machine agreeing about
     * the same row.
     */
    public function scopeBlockingReapplication(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('application_status')
                ->orWhereNotIn('application_status', ApplicationStateMachine::reappliableStatuses());
        });
    }

    /**
     * Applications a provider still has to decide: not accepted, not rejected,
     * not withdrawn.
     *
     * A NULL status counts, as do the legacy in-progress spellings - the state
     * machine reads both as pending, and `NULL NOT IN (...)` is NULL rather than
     * true, so the NULL case is stated explicitly. See
     * ApplicationStatus::settledSpellings().
     */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('application_status')
                ->orWhereNotIn('application_status', ApplicationStatus::settledSpellings());
        });
    }

    /** The same rule for a row already in memory. */
    public function blocksReapplication(): bool
    {
        return ! ApplicationStateMachine::allowsReapplication($this->application_status);
    }

    /** The live status, with any legacy value mapped onto it. */
    public function status(): string
    {
        return ApplicationStatus::canonical($this->application_status);
    }

    public function statusLabel(): string
    {
        return ApplicationStatus::displayLabel($this->application_status);
    }

    public function statusTone(): string
    {
        return ApplicationStatus::badgeTone($this->application_status);
    }

    public function isPending(): bool
    {
        return $this->status() === ApplicationStatus::PENDING;
    }

    public function isAccepted(): bool
    {
        return $this->status() === ApplicationStatus::ACCEPTED;
    }

    public function isRejected(): bool
    {
        return $this->status() === ApplicationStatus::REJECTED;
    }

    public function isWithdrawn(): bool
    {
        return $this->status() === ApplicationStatus::WITHDRAWN;
    }

    /** The provider has accepted or rejected, so nothing more will happen. */
    public function isDecided(): bool
    {
        return ApplicationStatus::isDecision($this->application_status);
    }

    public function isTerminal(): bool
    {
        return ApplicationStatus::isTerminal($this->application_status);
    }

    public function canBeWithdrawn(): bool
    {
        return ApplicationStateMachine::canWithdraw($this->application_status);
    }

    /** Whether the provider still has a decision to make on this application. */
    public function awaitsDecision(): bool
    {
        return ApplicationStateMachine::canDecide($this->application_status);
    }

    /**
     * Pending again because a provider withdrew an earlier decision, and not yet decided a second time. Providers can no
     * longer withdraw a decision; this reads applications reopened before that was removed.
     */
    public function isReopened(): bool
    {
        return $this->isPending() && $this->reopened_at !== null;
    }

    /**
     * What has been decided about this application, oldest first: each decision and each withdrawal of one, from
     * the audit log (the row itself only holds the current decision).
     *
     * @return \Illuminate\Support\Collection<int, array{kind: string, at: \Illuminate\Support\Carbon|null, by: ?string, reason: ?string, from: ?string}>
     */
    public function decisionHistory(): \Illuminate\Support\Collection
    {
        return AuditLog::where('entity_type', 'APPLICATION')
            ->where('entity_id', $this->application_id)
            ->whereIn('action', [AuditAction::STATUS_UPDATE, AuditAction::REOPEN_APPLICATION])
            ->orderBy('audit_id')
            ->get()
            ->map(function (AuditLog $entry) {
                $reopened = $entry->action === AuditAction::REOPEN_APPLICATION;

                return [
                    'kind' => $reopened
                        ? 'reopened'
                        : strtolower(ApplicationStatus::displayLabel($entry->new_values['application_status'] ?? null)),
                    'at' => $entry->created_at,
                    'by' => $entry->actor_email,
                    'reason' => $entry->reason,
                    'from' => $reopened ? strtolower(ApplicationStatus::displayLabel($entry->old_values['application_status'] ?? null)) : null,
                ];
            })
            ->values();
    }
}
