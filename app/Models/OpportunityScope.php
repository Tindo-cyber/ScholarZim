<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One thing a listing is open to: a programme, a field (broad or narrow), or an institution.
 * Exactly one of the three is set. A listing with no rows is open to anyone.
 *
 * Read together: the programmes and fields are ONE group (a student fits if their programme
 * is any listed programme or lies inside any listed field), the institutions are ANOTHER (a
 * student fits if they are at any listed institution), and when both groups exist a student
 * must fit both - "any Engineering programme at MSU or NUST".
 */
class OpportunityScope extends Model
{
    public $timestamps = false;

    protected $table = 'opportunity_scopes';

    protected $fillable = ['opportunity_id', 'programme_id', 'field_id', 'institution_id', 'source'];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class, 'opportunity_id', 'opportunity_id');
    }

    public function programme(): BelongsTo
    {
        return $this->belongsTo(Programme::class, 'programme_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'field_id');
    }

    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class, 'institution_id');
    }
}
