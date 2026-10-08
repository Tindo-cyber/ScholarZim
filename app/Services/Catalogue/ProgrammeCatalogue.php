<?php

namespace App\Services\Catalogue;

use App\Models\ApplicantProgramme;
use App\Models\Field;
use App\Models\OpportunityScope;
use App\Models\Programme;
use App\Models\ProgrammeSynonym;
use App\Models\User;
use App\Services\AuditService;
use App\Support\AuditAction;
use App\Support\EducationLevel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Searching the programme catalogue, taking suggestions for what is missing from it, and what
 * an administrator then does with them.
 *
 * Who can see what: the catalogue proper (approved and switched on) is visible to everyone. A
 * programme someone suggested is pending, and visible only to the person who suggested it, so
 * they can use it straight away without it leaking to everyone before it has been checked.
 */
class ProgrammeCatalogue
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    // ---------------------------------------------------------------- search --

    /**
     * @return Collection<int, Programme>
     */
    public function searchProgrammes(string $query, ?string $level = null, ?User $for = null, int $limit = 25): Collection
    {
        $term = trim($query);

        $programmes = Programme::query()
            ->with(['field.parent', 'synonyms'])
            ->where(fn (Builder $visible) => $visible
                ->where(fn (Builder $catalogue) => $catalogue->where('status', Programme::APPROVED)->where('is_active', true))
                ->when($for !== null, fn (Builder $q) => $q->orWhere(fn (Builder $own) => $own
                    ->where('status', Programme::PENDING)->where('suggested_by', $for->user_id))))
            ->atLevel($level);

        if ($term === '') {
            // Nothing typed: a level's worth, not the whole catalogue.
            return $level === null ? new Collection() : $programmes->orderBy('name')->limit($limit)->get();
        }

        // % and _ are characters a person can type, not wildcards.
        $like = '%' . addcslashes($term, '\\%_') . '%';

        return $programmes
            ->where(fn (Builder $match) => $match
                ->whereRaw("name LIKE ? ESCAPE '\\'", [$like])
                ->orWhereHas('synonyms', fn (Builder $s) => $s->whereRaw("synonym LIKE ? ESCAPE '\\'", [$like])))
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /** A programme by its exact name or one of its synonyms (ignoring case and punctuation). */
    public function findProgramme(string $name, ?string $level = null, ?User $for = null): ?Programme
    {
        $wanted = Programme::normalise($name);

        if ($wanted === '') {
            return null;
        }

        $candidates = Programme::query()
            ->with('synonyms')
            ->atLevel($level)
            ->where(fn (Builder $q) => $q->where('status', Programme::APPROVED)
                ->orWhere(fn (Builder $p) => $p->where('status', Programme::PENDING)->when($for, fn ($x) => $x->where('suggested_by', $for->user_id), fn ($x) => $x->whereRaw('1 = 0'))))
            ->where('is_active', true)
            ->get();

        return $candidates->first(fn (Programme $p) => Programme::normalise($p->name) === $wanted
            || $p->synonyms->contains(fn (ProgrammeSynonym $s) => Programme::normalise($s->synonym) === $wanted));
    }

    /**
     * A field (broad or narrow) by its exact name, ignoring case, punctuation and a trailing
     * "(ICTs)"-style bracket. Where a broad and a narrow field share a name (Education,
     * ICT) the narrow one wins: the stricter reading, so a name never opens a scope wider
     * than it says.
     */
    public function findField(string $name): ?Field
    {
        $wanted = $this->fieldKey($name);

        if ($wanted === '') {
            return null;
        }

        return Field::all()
            ->sortBy(fn (Field $f) => $f->isBroad() ? 1 : 0)
            ->first(fn (Field $f) => $this->fieldKey($f->name) === $wanted);
    }

    private function fieldKey(string $name): string
    {
        return Programme::normalise((string) preg_replace('/\([^)]*\)/', '', $name));
    }

    // ----------------------------------------------------------- suggestions --

    /**
     * "My programme isn't listed." Returns the programme to use: the one already in the
     * catalogue when the name matches it (so nobody creates a second copy), otherwise a new
     * pending one.
     *
     * @throws \InvalidArgumentException when the suggestion itself is unusable
     * @throws \DomainException when this person has too many waiting already
     */
    public function suggest(User $user, string $name, string $level, int $fieldId): Programme
    {
        $name = trim((string) preg_replace('/\s+/', ' ', strip_tags($name)));
        $name = (string) preg_replace('/[\x00-\x1F\x7F<>]/', '', $name);
        $canonical = EducationLevel::canonical($level);

        if (mb_strlen($name) < 3 || mb_strlen($name) > 200 || ! preg_match('/\p{L}/u', $name)) {
            throw new \InvalidArgumentException('Give the programme\'s name (3 to 200 characters).');
        }

        if (! in_array($canonical, Programme::LEVELS, true)) {
            throw new \InvalidArgumentException('Choose a level: Certificate, Diploma, Undergraduate, Postgraduate, Masters or PhD.');
        }

        if (! Field::whereKey($fieldId)->exists()) {
            throw new \InvalidArgumentException('Choose the field the programme belongs to.');
        }

        // Anything already in the catalogue, or already suggested by anyone, is reused.
        $existing = Programme::query()->with('synonyms')->atLevel($canonical)
            ->whereIn('status', [Programme::APPROVED, Programme::PENDING])->get()
            ->first(fn (Programme $p) => Programme::normalise($p->name) === Programme::normalise($name)
                || $p->synonyms->contains(fn (ProgrammeSynonym $s) => Programme::normalise($s->synonym) === Programme::normalise($name)));

        if ($existing !== null) {
            return $existing;
        }

        $limit = max(1, (int) config('scholarzim.catalogue.max_pending_per_user', 10));

        if (Programme::pending()->where('suggested_by', $user->user_id)->count() >= $limit) {
            throw new \DomainException('You already have ' . $limit . ' programmes waiting for approval. Please wait for those to be checked.');
        }

        $programme = Programme::create([
            'name' => $name,
            'education_level' => $canonical,
            'field_id' => $fieldId,
            'is_active' => true,
            'status' => Programme::PENDING,
            'suggested_by' => $user->user_id,
        ]);

        $this->audit->log($user->email, AuditAction::CATALOGUE_PROGRAMME_SUGGESTED, 'Programme', $programme->id, $name);

        return $programme;
    }

    // ---------------------------------------------------------- administration --

    public function approve(Programme $programme, User $admin): void
    {
        $this->mustBePending($programme);

        $programme->update(['status' => Programme::APPROVED]);

        $this->audit->log($admin->email, AuditAction::CATALOGUE_PROGRAMME_APPROVED, 'Programme', $programme->id, $programme->name);
    }

    /**
     * It was the same programme under another name: everything pointing at the suggestion
     * moves to the existing one, and the suggested name becomes a synonym so the next person
     * who types it finds the right programme.
     */
    public function merge(Programme $pending, Programme $target, User $admin): void
    {
        $this->mustBePending($pending);

        if ($target->id === $pending->id || $target->status !== Programme::APPROVED) {
            throw new \InvalidArgumentException('Merge into a programme that is in the catalogue.');
        }

        if ($target->education_level !== $pending->education_level) {
            throw new \InvalidArgumentException('A programme can only be merged into one at the same level.');
        }

        DB::transaction(function () use ($pending, $target): void {
            // A student who already chose both keeps one row, not a violated unique key.
            $profilesWithTarget = ApplicantProgramme::where('programme_id', $target->id)->pluck('profile_id')->all();
            ApplicantProgramme::where('programme_id', $pending->id)->whereIn('profile_id', $profilesWithTarget)->delete();
            ApplicantProgramme::where('programme_id', $pending->id)->update(['programme_id' => $target->id]);

            OpportunityScope::where('programme_id', $pending->id)->update(['programme_id' => $target->id]);

            $known = $target->synonyms()->pluck('synonym')->map(fn ($s) => Programme::normalise($s))->all();

            if (Programme::normalise($pending->name) !== Programme::normalise($target->name) && ! in_array(Programme::normalise($pending->name), $known, true)) {
                ProgrammeSynonym::create(['programme_id' => $target->id, 'synonym' => $pending->name]);
            }

            $pending->update(['status' => 'merged', 'is_active' => false, 'merged_into_id' => $target->id]);
        });

        $this->audit->log($admin->email, AuditAction::CATALOGUE_PROGRAMME_MERGED, 'Programme', $pending->id, $pending->name . ' -> ' . $target->name);
    }

    public function reject(Programme $pending, User $admin): void
    {
        $this->mustBePending($pending);

        DB::transaction(function () use ($pending): void {
            ApplicantProgramme::where('programme_id', $pending->id)->delete();
            OpportunityScope::where('programme_id', $pending->id)->delete();
            $pending->update(['status' => 'rejected', 'is_active' => false]);
        });

        $this->audit->log($admin->email, AuditAction::CATALOGUE_PROGRAMME_REJECTED, 'Programme', $pending->id, $pending->name);
    }

    private function mustBePending(Programme $programme): void
    {
        if (! $programme->isPending()) {
            throw new \DomainException('Only a programme waiting for approval can be approved, merged or rejected.');
        }
    }
}
