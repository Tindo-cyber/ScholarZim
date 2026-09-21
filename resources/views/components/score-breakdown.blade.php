@props([
    /** A ScoredOpportunity, or anything carrying a ->breakdown. */
    'fit' => null,
    /**
     * The same six rows in MatchBreakdown::dimensions() shape, for a caller
     * that holds the rows but no ScoredOpportunity - the administrator's
     * ScholarFit page scores a sample in memory and keeps only the rows. Given
     * instead of `fit`, never as well as it.
     */
    'dimensions' => null,
    /*
     * "Why this score" is the question the reader is actually asking, and it is
     * the wording the app already used before this became a component.
     * EligibilityExplanationTest asserts on it to prove the score breakdown is
     * rendered after the eligibility verdict and not at all beside a refusal -
     * so the string is load-bearing, not decoration.
     */
    'title' => 'Why this score',
    /**
     * The sentence above the bars. The default addresses an applicant, because
     * that is who reads this panel almost everywhere; the one page that shows
     * it to an administrator says something else.
     */
    'note' => 'A match score describes how closely this scholarship fits your profile. It is separate'
        . ' from whether you are eligible, and the provider decides who is awarded.',
    /** Open on a detail page where it is the point; closed in a list. */
    'open' => false,
    'id' => null,
])

@php
    /**
     * The six dimensions, read straight off the DimensionResult objects the
     * score was summed from.
     *
     * Nothing is calculated here. points(), max and verdict() are the engine's
     * own, so a bar cannot disagree with the number beside it, and changing a
     * weight in config changes this display without anybody editing a view.
     *
     * The weights are not restated in this file for the same reason. A "25%"
     * typed into a template is a second opinion waiting to go stale; every
     * figure below comes from the row's own max.
     *
     * Both shapes are flattened to one list here so the markup below has a
     * single thing to render. The array shape is MatchBreakdown::dimensions()'s
     * own output, not a second format invented for this component.
     */
    $rows = $dimensions !== null
        ? array_map(static fn (array $row) => [
            'label' => $row['label'],
            'points' => $row['score'],
            'max' => $row['max'],
            'detail' => $row['detail'] ?? '',
            'verdict' => $row['verdict'] ?? '',
            'ratio' => ($row['max'] ?? 0) > 0 ? $row['score'] / $row['max'] : 0.0,
        ], $dimensions)
        : array_map(static fn ($dimension) => [
            'label' => $dimension->label,
            'points' => $dimension->points(),
            'max' => $dimension->max,
            'detail' => $dimension->detail,
            'verdict' => $dimension->verdict(),
            'ratio' => $dimension->ratio,
        ], $fit?->breakdown->dimensionResults ?? []);

    $total = array_sum(array_column($rows, 'points'));
    $ceiling = array_sum(array_column($rows, 'max'));
    $panelId = $id ?? 'sz-score-breakdown-' . \Illuminate\Support\Str::random(6);
@endphp

@if($rows !== [])
    {{--
        <details> rather than a Bootstrap collapse: it is a disclosure widget in
        the browser already - keyboard operable, announced as expandable, open
        when printed - and it needs no JavaScript, which matters on a page whose
        script budget is one bundle.
    --}}
    <details class="sz-score-breakdown" @if($open) open @endif>
        <summary class="sz-score-breakdown-summary" aria-controls="{{ $panelId }}">
            <span class="fw-semibold">{{ $title }}</span>
            <span class="text-secondary small ms-auto">{{ $total }}/{{ $ceiling }}</span>
            <x-icon name="chart" :size="16" class="text-secondary" />
        </summary>

        <div id="{{ $panelId }}" class="pt-3">
            {{--
                A match score says how well a listing fits; it is not permission
                to apply, and it is not what decides an application. Said here
                because this panel is the most numerical thing an applicant
                sees, and a number with a bar under it invites being read as a
                verdict.
            --}}
            <p class="small text-secondary">{{ $note }}</p>

            <ul class="list-unstyled d-grid gap-3 mb-0">
                @foreach($rows as $row)
                    @php
                        $percent = $row['max'] > 0
                            ? (int) round($row['points'] / $row['max'] * 100)
                            : 0;
                        $tone = match (true) {
                            $row['ratio'] >= 0.75 => 'success',
                            $row['ratio'] >= 0.5 => 'primary',
                            $row['ratio'] > 0 => 'warning',
                            default => 'secondary',
                        };
                    @endphp

                    <li>
                        <div class="d-flex flex-wrap align-items-baseline gap-2">
                            <span class="fw-semibold small">{{ $row['label'] }}</span>
                            <span class="text-secondary small ms-auto sz-tabular">
                                {{ $row['points'] }}/{{ $row['max'] }}
                            </span>
                        </div>

                        {{--
                            The bar is decoration over a number that is already
                            written above it, so it carries aria-hidden and the
                            <progress>-style roles are left off: a screen reader
                            reads "Academic 14/20. Strong match." and is not made
                            to sit through a second rendering of the same fact.
                        --}}
                        <div class="progress sz-progress-thin my-1" aria-hidden="true">
                            <div class="progress-bar bg-{{ $tone }}" style="width: {{ $percent }}%"></div>
                        </div>

                        {{-- The engine writes its explanations out of the values it
                             scored on, which is what keeps the two in step - and
                             leaves stored tokens like O_LEVEL in the sentence.
                             ScholarFitCopy restates the same facts in a student's
                             words without touching what was decided. --}}
                        <p class="small text-secondary mb-0">
                            <span class="text-{{ $tone }} fw-semibold">{{ $row['verdict'] }}.</span>
                            {{ \App\Support\ScholarFitCopy::humanise($row['detail']) }}
                        </p>
                    </li>
                @endforeach
            </ul>

            @isset($footer)
                <div class="mt-3">{{ $footer }}</div>
            @endisset
        </div>
    </details>
@endif
