<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A scholarship one applicant has deliberately set aside. See the migration for why this is not SavedScholarship. */
class HeldBackScholarship extends Model
{
    protected $table = 'held_back_scholarships';

    protected $primaryKey = 'held_back_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'opportunity_id', 'held_back_at'];

    protected $casts = [
        'held_back_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }
}
