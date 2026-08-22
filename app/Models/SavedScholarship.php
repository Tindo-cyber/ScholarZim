<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedScholarship extends Model
{
    protected $table = 'saved_scholarships';

    protected $primaryKey = 'saved_id';

    public $timestamps = false;

    protected $fillable = ['user_id', 'opportunity_id', 'saved_at'];

    protected $casts = [
        'saved_at' => 'datetime',
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
