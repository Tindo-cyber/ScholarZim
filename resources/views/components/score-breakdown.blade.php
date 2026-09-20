@props([
    /** A ScoredOpportunity, or anything carrying a ->breakdown. */
    'fit',
    /*
     * "Why this score" is the question the reader is actually asking, and it is
     * the wording the app already used before this became a component.
     * EligibilityExplanationTest asserts on it to prove the score breakdown is
     * rendered after the eligibility verdict and not at all beside a refusal -
     * so the string is load-bearing, not decoration.
     */
    'title' => 'Why this score',
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
     * figure below comes from $dimension->max.
     */
    $breakdown = $fit->breakdown;
    $dimensions = $breakdown->dimensionResults;
    $total = $breakdown->totalScore();
    $ceiling = array_sum(array_map(static fn ($d) => $d->max, $dimensions));
    $panelId = $id ?? 'sz-score-breakdown-' . \Illuminate\Support\Str::random(6);
@endphp

@if($dimensions !== [])
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
            <p class="small text-secondary">
                A match score describes how closely this scholarship fits your profile.
                It is separate from whether you are eligible, and the provider decides
                who is awarded.
            </p>

            <ul class="list-unstyled d-grid gap-3 mb-0">
                @foreach($dimensions as $dimension)
                    @php
                        $percent = $dimension->max > 0
                            ? (int) round($dimension->points() / $dimension->max * 100)
                            : 0;
                        $tone = match (true) {
                            $dimension->ratio >= 0.75 => 'success',
                            $dimension->ratio >= 0.5 => 'primary',
                            $dimension->ratio > 0 => 'warning',
                            default => 'secondary',
                        };
                    @endphp

                    <li>
                        <div class="d-flex flex-wrap align-items-baseline gap-2">
                            <span class="fw-semibold small">{{ $dimension->label }}</span>
                            <span class="text-secondary small ms-auto sz-tabular">
                                {{ $dimension->points() }}/{{ $dimension->max }}
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

                        <p class="small text-secondary mb-0">
                            <span class="text-{{ $tone }} fw-semibold">{{ $dimension->verdict() }}.</span>
                            {{ $dimension->detail }}
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
