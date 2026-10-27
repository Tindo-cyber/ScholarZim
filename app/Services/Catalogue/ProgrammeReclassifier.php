<?php

namespace App\Services\Catalogue;

use App\Models\Field;
use App\Models\Programme;
use App\Models\ProgrammeFieldReview;
use App\Services\AuditService;
use App\Support\AuditAction;
use Illuminate\Support\Facades\DB;

/**
 * Files the catalogue's programmes under the most specific ISCED-F field their names justify.
 *
 * The catalogue began with each programme in a narrow field (three digits). The classification now has a detailed level (four
 * digits), so a programme can say more: Finance rather than all of Business and administration. What a programme's name says
 * is written in ProgrammeFieldRules, with the manual's wording as the basis for each rule.
 *
 * What this does by itself, and only on --apply: a "refine" (the programme moves to a detailed field inside the narrow field it
 * is already in) where the name is about one discipline the manual lists there ("high"). That narrows a statement that was true
 * into one that is more exact, and it is written to the audit log with the old and new field.
 *
 * What it never does by itself: a "move" to a field in a different narrow field, or any proposal it is only fairly sure of.
 * Those are queued as reviews for an administrator. A programme no rule recognises stays in its narrow field, which is a true
 * statement rather than a guess, and ScholarFit does not treat it as an exact match for any detailed field below it.
 *
 * Nothing here touches an application, its snapshot, a listing, or a scope: those keep the field ids they were saved with.
 */
final class ProgrammeReclassifier
{
    /** @var array<string, array<int, array{0: string, 1: string, 2: string, 3: string}>>|null */
    private ?array $rules = null;

    /** @var array<string, Field>|null */
    private ?array $byCode = null;

    public function __construct(private readonly AuditService $audit)
    {
    }

    /**
     * What the rules propose for one programme, or null when it is already right or no rule applies.
     *
     * @return ?array{to: Field, from: ?Field, kind: string, confidence: string, reason: string}
     */
    public function propose(Programme $programme): ?array
    {
        $current = $programme->field;

        if ($current === null || $current->level === Field::LEVEL_BROAD) {
            return null;
        }

        $this->load();

        foreach ($this->rules[substr($current->code, 0, 3)] ?? [] as [$pattern, $code, $confidence, $basis]) {
            if (! preg_match($pattern, $programme->name)) {
                continue;
            }

            $to = $this->byCode[$code] ?? null;

            if ($to === null || ! $to->is_active || $to->id === $current->id) {
                return null;
            }

            $kind = $to->isWithin($current) ? ProgrammeFieldReview::REFINE : ProgrammeFieldReview::MOVE;

            return [
                'to' => $to,
                'from' => $current,
                'kind' => $kind,
                'confidence' => $confidence,
                'reason' => $basis . ' (' . $to->code . ' ' . $to->name . ')',
            ];
        }

        return null;
    }

    /**
     * Go through every programme. With $apply false nothing is written.
     *
     * @return array{rows: array<int, array<string, mixed>>, applied: int, queued: int, unchanged: int, unmatched: int}
     */
    public function run(bool $apply): array
    {
        $this->byCode = null;
        $rows = [];
        $applied = $queued = $unchanged = $unmatched = 0;

        Programme::query()->with('field')->orderBy('id')->chunkById(200, function ($programmes) use ($apply, &$rows, &$applied, &$queued, &$unchanged, &$unmatched) {
            foreach ($programmes as $programme) {
                $proposal = $this->propose($programme);

                if ($proposal === null) {
                    $programme->field !== null && $programme->field->level !== Field::LEVEL_DETAILED ? $unmatched++ : $unchanged++;

                    continue;
                }

                $auto = $proposal['kind'] === ProgrammeFieldReview::REFINE && $proposal['confidence'] === 'high';
                $rows[] = [
                    'programme_id' => $programme->id,
                    'programme' => $programme->name,
                    'level' => $programme->education_level,
                    'from' => $proposal['from']->code . ' ' . $proposal['from']->name,
                    'to' => $proposal['to']->code . ' ' . $proposal['to']->name,
                    'kind' => $proposal['kind'],
                    'confidence' => $proposal['confidence'],
                    'action' => $auto ? 'applied' : 'queued for review',
                    'reason' => $proposal['reason'],
                ];

                $auto ? $applied++ : $queued++;

                if ($apply) {
                    $auto ? $this->apply($programme, $proposal) : $this->queue($programme, $proposal);
                }
            }
        });

        return compact('rows', 'applied', 'queued', 'unchanged', 'unmatched');
    }

    /** Make a proposal real: the programme moves, with the audit line in the same transaction. */
    public function apply(Programme $programme, array $proposal, string $actor = 'system'): void
    {
        DB::transaction(function () use ($programme, $proposal, $actor) {
            $old = ['field_id' => $programme->field_id, 'field' => $proposal['from']?->code];
            $programme->forceFill(['field_id' => $proposal['to']->id])->save();

            $this->audit->logOrFail(
                $actor,
                AuditAction::CATALOGUE_CHANGED,
                'Programme',
                $programme->id,
                'Filed under ' . $proposal['to']->code . ' ' . $proposal['to']->name . ' instead of ' . ($proposal['from']?->code ?? 'nothing'),
                ['old' => $old, 'new' => ['field_id' => $proposal['to']->id, 'field' => $proposal['to']->code], 'reason' => $proposal['reason']]
            );

            ProgrammeFieldReview::where('programme_id', $programme->id)->where('to_field_id', $proposal['to']->id)
                ->where('status', ProgrammeFieldReview::PENDING)
                ->update(['status' => ProgrammeFieldReview::APPLIED, 'decided_by' => $actor, 'decided_at' => now()]);
        });
    }

    /** Leave a proposal for a person; a decision already made on it is not undone. */
    private function queue(Programme $programme, array $proposal): void
    {
        $review = ProgrammeFieldReview::firstOrNew(['programme_id' => $programme->id, 'to_field_id' => $proposal['to']->id]);

        if ($review->exists && $review->status !== ProgrammeFieldReview::PENDING) {
            return;
        }

        $review->fill([
            'from_field_id' => $proposal['from']?->id,
            'kind' => $proposal['kind'],
            'confidence' => $proposal['confidence'],
            'reason' => mb_substr($proposal['reason'], 0, 500),
            'status' => ProgrammeFieldReview::PENDING,
        ])->save();
    }

    private function load(): void
    {
        $this->rules ??= ProgrammeFieldRules::all();
        $this->byCode ??= Field::query()->get()->keyBy('code')->all();
    }
}
