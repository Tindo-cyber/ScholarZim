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
    ];

    protected $casts = [
        'submitted_at' => 'datetime',
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
}
