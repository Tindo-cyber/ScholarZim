<?php

namespace App\Models;

use App\Support\EducationLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Something a person studies: "BSc Information Systems", "Diploma in Accountancy".
 *
 * `approved` programmes are the catalogue. A `pending` one was typed by a person whose
 * programme was not listed; it waits for an administrator to approve it or merge it into an
 * existing programme (leaving `merged_into_id` and a synonym behind).
 */
class Programme extends Model
{
    public const APPROVED = 'approved';

    public const PENDING = 'pending';

    /** The levels a programme can be at. School levels have no programmes. */
    public const LEVELS = [
        EducationLevel::CERTIFICATE,
        EducationLevel::DIPLOMA,
        EducationLevel::UNDERGRADUATE,
        EducationLevel::POSTGRADUATE,
        EducationLevel::MASTERS,
        EducationLevel::PHD,
    ];

    protected $table = 'programmes';

    protected $fillable = ['name', 'education_level', 'field_id', 'is_active', 'status', 'suggested_by', 'merged_into_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function field(): BelongsTo
    {
        return $this->belongsTo(Field::class, 'field_id');
    }

    public function synonyms(): HasMany
    {
        return $this->hasMany(ProgrammeSynonym::class, 'programme_id');
    }

    public function institutions(): BelongsToMany
    {
        return $this->belongsToMany(Institution::class, 'institution_programme', 'programme_id', 'institution_id');
    }

    public function suggester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'suggested_by', 'user_id');
    }

    /** In the catalogue proper: approved and switched on. */
    public function scopeInCatalogue(Builder $query): Builder
    {
        return $query->where('status', self::APPROVED)->where('is_active', true);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::PENDING);
    }

    public function scopeAtLevel(Builder $query, ?string $level): Builder
    {
        $canonical = EducationLevel::canonical($level);

        return $canonical === null ? $query : $query->where('education_level', $canonical);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function levelLabel(): string
    {
        return EducationLevel::label($this->education_level);
    }

    /** Lower-cased, punctuation folded to single spaces: how two spellings are compared. */
    public static function normalise(?string $value): string
    {
        $clean = strtolower(trim((string) $value));
        $clean = str_replace('&', ' and ', $clean);
        $clean = (string) preg_replace('/[^a-z0-9]+/', ' ', $clean);

        return trim($clean);
    }
}
