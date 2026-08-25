<?php

namespace App\Models;

use App\Support\ApplicationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Application extends Model
{
    protected $table = 'applications';

    protected $primaryKey = 'application_id';

    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'opportunity_id',
        'application_status',
        'submitted_at',
        'personal_statement',
        'document_filename',
        'document_path',
        'rejection_reason',
        'interview_at',
        'withdrawn_at',
        'withdrawal_reason',
        'info_request',
        'info_requested_at',
        'info_response',
        'info_responded_at',
        'interview_reminded_at',
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
        'interview_at' => 'datetime',
        'withdrawn_at' => 'datetime',
        'info_requested_at' => 'datetime',
        'info_responded_at' => 'datetime',
        'interview_reminded_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }

    public function statusLabel(): string
    {
        return ApplicationStatus::displayLabel($this->application_status);
    }

    public function statusTone(): string
    {
        return ApplicationStatus::badgeTone($this->application_status);
    }

    public function isTerminal(): bool
    {
        return ApplicationStatus::isTerminal($this->application_status);
    }

    public function isWithdrawn(): bool
    {
        return $this->application_status === ApplicationStatus::WITHDRAWN;
    }

    public function canBeWithdrawn(): bool
    {
        return ApplicationStatus::isWithdrawable($this->application_status);
    }

    /**
     * The provider asked something and the applicant has not answered it yet.
     * A later answer sets info_responded_at, which closes the reply box even
     * though the status only moves when the provider acts on the answer.
     */
    public function awaitsApplicantResponse(): bool
    {
        return ApplicationStatus::awaitsApplicant($this->application_status)
            && $this->info_requested_at !== null
            && ($this->info_responded_at === null
                || $this->info_responded_at->lt($this->info_requested_at));
    }

    public function hasUpcomingInterview(): bool
    {
        return $this->interview_at !== null && $this->interview_at->isFuture();
    }
}
