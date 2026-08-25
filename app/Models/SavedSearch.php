<?php

namespace App\Models;

use App\Support\FormOptions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A set of browse filters an applicant asked to be told about.
 *
 * The filters are stored as the same associative array OpportunityService::search()
 * takes, so an alert run is literally "re-run this search" - there is no second
 * matching implementation to keep in step with the one students see.
 */
class SavedSearch extends Model
{
    protected $table = 'saved_searches';

    protected $primaryKey = 'saved_search_id';

    protected $fillable = [
        'user_id',
        'name',
        'filters',
        'alerts_enabled',
        'last_alerted_opportunity_id',
        'last_alerted_at',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'filters' => 'array',
        'alerts_enabled' => 'boolean',
        'last_alerted_opportunity_id' => 'integer',
        'last_alerted_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /** Filter keys a saved search may carry, mirroring the browse filter bar. */
    public const FILTER_KEYS = [
        'keyword',
        'education_level',
        'country',
        'field_of_study',
        'provider',
        'funding_type',
        'min_award',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'user_id');
    }

    /** Only the keys the search engine understands, with blanks dropped. */
    public function activeFilters(): array
    {
        $filters = $this->filters ?? [];

        return array_filter(
            array_intersect_key($filters, array_flip(self::FILTER_KEYS)),
            static fn ($value) => filled($value)
        );
    }

    /** Human-readable "Masters · Computer Science · Zimbabwe" for the saved list. */
    public function summary(): string
    {
        $labels = [
            'keyword' => 'matching',
            'education_level' => 'level',
            'field_of_study' => 'field',
            'country' => 'in',
            'provider' => 'from',
            'funding_type' => 'funding',
            'min_award' => 'at least',
        ];

        $parts = [];
        foreach ($this->activeFilters() as $key => $value) {
            $prefix = $labels[$key] ?? $key;
            $shown = $key === 'min_award'
                ? FormOptions::DEFAULT_CURRENCY . ' ' . number_format((float) $value)
                : $value;
            $parts[] = $prefix . ' ' . $shown;
        }

        return $parts === [] ? 'Every new scholarship' : implode(' · ', $parts);
    }
}
