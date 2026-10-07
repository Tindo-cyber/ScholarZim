<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One applicant's report of one listing. See ReportStatus and the opportunity_reports migration. */
class OpportunityReport extends Model
{
    protected $table = 'opportunity_reports';

    protected $primaryKey = 'report_id';

    public $timestamps = false;

    protected $fillable = [
        'opportunity_id',
        'user_id',
        'reason',
        'details',
        'status',
        'reviewed_by',
        'reviewed_at',
        'created_at',
    ];

    protected $casts = [
        'reviewed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }
}
