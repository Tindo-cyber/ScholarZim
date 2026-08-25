<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One row per listing per day; the provider funnel reads it as a trend line. */
class OpportunityView extends Model
{
    protected $table = 'opportunity_views';

    public $timestamps = false;

    protected $fillable = [
        'opportunity_id',
        'viewed_on',
        'views',
    ];

    protected $casts = [
        'viewed_on' => 'date',
        'views' => 'integer',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }
}
