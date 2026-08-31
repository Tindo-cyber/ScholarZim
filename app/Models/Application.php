<?php

namespace App\Models;

use App\Support\ApplicationStateMachine;
use App\Support\ApplicationStatus;
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
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
        'withdrawn_at' => 'datetime',
    ];

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
}
