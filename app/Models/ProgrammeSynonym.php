<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Another name for a programme: an abbreviation, an older name, a common short form. */
class ProgrammeSynonym extends Model
{
    public $timestamps = false;

    protected $table = 'programme_synonyms';

    protected $fillable = ['programme_id', 'synonym'];

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }
}
