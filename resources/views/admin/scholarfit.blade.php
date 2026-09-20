@extends('layouts.app')

@section('title', 'ScholarFit weights')

@php
    /**
     * The near-miss credit, read from the key the engine actually uses.
     *
     * The page was rendering "a near miss earns 0% of the dimension's weight
     * rather than nothing", which is self-contradictory and also wrong: the
     * figure reached the view as config('scholarfit.related_credit'), a key
     * that does not exist in config/scholarfit.php, so it arrived null and was
     * cast to zero. The real fraction lives at scholarfit.credit.related and is
     * what EducationMatcher and FieldMatcher award for an adjacent level or a
     * related field.
     *
     * Read here rather than corrected in the controller because the controller
     * is backend: the broken line is ScholarFitController::pageData()'s
     * 'relatedCredit', and it is reported rather than edited.
     */
    $relatedCreditPercent = (int) round(((float) config('scholarfit.credit.related')) * 100);

    /**
     * What each dimension actually influences, in an administrator's words.
     *
     * Keyed on the same dimension keys the controller and config use, so a key
     * without a sentence here simply gets none rather than the wrong one. The
     * numbers are never restated - they come from $weights and $defaults - and
     * neither is the behaviour: each line describes what the matcher of that
     * name already does.
     */
    $explanations = [
        'academic' => 'How strong the applicant\'s recorded results are, measured against the bar this listing sets where it sets one.',
        'education_level' => 'How close the level they have reached is to the level the award is for, with credit for a recognised step towards it.',
        'field' => 'Whether their field of study is the one the award targets, or a related one.',
        'location' => 'Province, locality and settlement type - each only assessed when the listing actually names it.',
        'deadline' => 'How soon the award closes. Sooner scores higher, so awards about to close surface first; it is a tie-breaker, not a judgement of the applicant.',
        'certificate' => 'Whether they hold the academic evidence their level calls for - a results certificate, or a transcript.',
    ];
@endphp

@section('content')

    <x-page-header title="ScholarFit weights"
                   subtitle="How much each dimension contributes to a student's match score."
                   eyebrow="Decision support">
        <x-slot:actions>
            @unless($isDefault)
                <form method="POST" action="{{ route('admin.scholarfit.reset') }}" class="m-0">
                    @csrf
                    <x-submit-button label="Reset to defaults" tone="outline-secondary" busy-label="Resetting..." />
                </form>
            @endunless
        </x-slot:actions>
    </x-page-header>

    {{--
        The distinction this page exists to make, said before anything is
        adjusted rather than in a sidebar note underneath the controls.

        A weight and a requirement are different kinds of thing, and confusing
        them is the expensive mistake available on this screen: raising
        "Academic record" to 60 does not make a weak applicant ineligible, and
        lowering it to 0 does not let one past a listing's stated minimum. One
        orders a list; the other decides who may be on it at all.
    --}}
    <div class="alert alert-primary d-flex gap-2 mb-4" role="note">
        <x-icon name="shield" :size="18" class="flex-shrink-0 mt-1" />
        <div>
            <p class="fw-semibold mb-1">The weights influence the match score.</p>
            <p class="mb-0">
                The weights do not override mandatory eligibility requirements. Eligibility is decided
                separately, from the rules each provider sets on their own listing, and no weight on this
                page can admit an applicant who fails one or exclude an applicant who meets them all.
            </p>
        </div>
    </div>

    <h2 class="h5 fw-bold mb-3">Match score weights</h2>

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('admin.scholarfit.update') }}" id="scholarfit-weights-form">
                @csrf

                <div class="card mb-4">
                    <div class="card-header d-flex flex-wrap gap-2 align-items-center justify-content-between">
                        <h3 class="h6 fw-semibold mb-0">The six dimensions</h3>
                        <x-status-badge :label="$isDefault ? 'Shipped defaults' : 'Customised'"
                                        :tone="$isDefault ? 'secondary' : 'primary'" />
                    </div>

                    <div class="card-body">
                        <p class="text-secondary">
                            Every score is presented to students as a percentage, so the six weights must total
                            exactly 100. Raising one means lowering another - that is the whole trade-off, and
                            the running total below shows where you are. The total is enforced when you save,
                            whatever this page says.
                        </p>

                        @error('weights')
                            <div class="alert alert-danger" role="alert">{{ $message }}</div>
                        @enderror

                        <div class="d-grid gap-4" data-weights-group>
                            @foreach($labels as $key => $label)
                                <div>
                                    <div class="row g-2 align-items-center">
                                        <div class="col-12 col-md-4">
                                            <label class="form-label mb-0 fw-semibold" for="weight-{{ $key }}">{{ $label }}</label>
                                            <div class="small text-secondary">Default {{ $defaults[$key] }}</div>
                                        </div>
                                        {{--
                                            col-7/col-5 below md, not col-8/col-4.
                                            At 360px a third of the row leaves the
                                            number field 73px wide - narrow enough
                                            that "100" is cut off by the spinner
                                            arrows, on the one control whose value
                                            is the entire point of the screen. The
                                            slider gives up the width because it
                                            degrades gracefully and the field does
                                            not.
                                        --}}
                                        <div class="col-7 col-md-6">
                                            {{--
                                                max matches the number input beside
                                                it. It used to stop at 60 while the
                                                field accepted 100, so a weight
                                                above 60 - which the 100-point total
                                                permits - showed the slider pinned at
                                                a value that was not the one stored,
                                                and nudging it silently rewrote the
                                                real number down to 60.
                                            --}}
                                            <input type="range" class="form-range" min="0" max="100" step="1"
                                                   id="weight-range-{{ $key }}"
                                                   value="{{ old('weights.' . $key, $weights[$key]) }}"
                                                   data-weight-range="{{ $key }}"
                                                   aria-label="{{ $label }} weight slider">
                                        </div>
                                        <div class="col-5 col-md-2">
                                            <input type="number" class="form-control sz-tabular" min="0" max="100" step="1"
                                                   id="weight-{{ $key }}"
                                                   name="weights[{{ $key }}]"
                                                   value="{{ old('weights.' . $key, $weights[$key]) }}"
                                                   data-weight-input="{{ $key }}"
                                                   aria-describedby="weight-help-{{ $key }}">
                                        </div>
                                    </div>

                                    @isset($explanations[$key])
                                        <p class="small text-secondary mb-0 mt-1" id="weight-help-{{ $key }}">
                                            {{ $explanations[$key] }}
                                        </p>
                                    @endisset
                                </div>
                            @endforeach
                        </div>

                        <hr>

                        <div class="d-flex align-items-center justify-content-between">
                            <span class="fw-semibold">Total</span>
                            <span class="fs-4 fw-bold sz-tabular" data-weights-total aria-live="polite">
                                {{ array_sum($weights) }}
                            </span>
                        </div>
                        <div class="progress mt-2 sz-progress-thin">
                            <div class="progress-bar" data-weights-bar
                                 style="width: {{ min(100, array_sum($weights)) }}%"></div>
                        </div>
                        <p class="form-text mb-0" data-weights-message>Weights must add up to 100.</p>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <x-submit-button label="Save weights" size="lg" busy-label="Saving..." />
                    <a class="btn btn-outline-secondary btn-lg" href="{{ route('admin.dashboard') }}">Cancel</a>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h3 class="h6 fw-semibold mb-0">Worked example</h3>
                </div>
                <div class="card-body">
                    {{--
                        The label reads the thresholds the engine reads.

                        It used to say "Perfect match" beside a score of 87. The
                        sample is not a perfect match and cannot be: the sample
                        listing names no location, so that dimension takes its
                        neutral half mark, and the academic dimension is graded
                        rather than passed. A worked example that mislabels its
                        own result is worse than no worked example, because the
                        number underneath it is the one being explained.
                    --}}
                    @php
                        $sampleReading = match (true) {
                            $sample['score'] >= $confidence['high'] => 'Strong match',
                            $sample['score'] >= $confidence['medium'] => 'Possible match',
                            default => 'Weak match',
                        };
                    @endphp

                    <p class="small text-secondary">
                        A complete undergraduate profile scored against a listing at the same education
                        level and in the same field, under the weights currently in force. The rows below
                        say where each dimension fell short of its maximum.
                    </p>

                    <div class="text-center mb-3">
                        <x-match-score :score="$sample['score']" size="lg" :label="$sampleReading" />
                    </div>

                    {{--
                        The same breakdown component the applicant and the provider
                        see, fed the sample's own rows. An administrator adjusting a
                        weight is adjusting exactly this panel, so showing them a
                        second, hand-drawn version of it would be showing them
                        something that could drift from the real one.
                    --}}
                    <x-score-breakdown :dimensions="$sample['dimensions']"
                                       :open="true"
                                       title="Where the points came from"
                                       note="The same panel an applicant sees, scored here against a sample profile. The bars move as you change the weights and save." />
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h3 class="h6 fw-semibold mb-0">How a score reads</h3>
                </div>
                <div class="card-body small">
                    <p>
                        A near miss - a related field of study, or an adjacent education level - earns
                        {{ $relatedCreditPercent }}% of the dimension's weight rather than nothing.
                    </p>
                    <p class="mb-0">
                        A score of {{ $confidence['high'] }} or more reads as a strong match,
                        {{ $confidence['medium'] }} or more as a possible one. Changing a weight changes every
                        score immediately; archived reports keep the numbers they were generated with.
                    </p>
                </div>
            </div>
        </div>
    </div>

    <h2 class="h5 fw-bold mb-3 mt-2">Hard eligibility rules</h2>

    {{--
        Promoted out of the sidebar. This was a card headed "What these do not
        control", sitting below a worked example, in the narrow column - which
        is where you put a footnote, not where you put the other half of how the
        system decides anything.
    --}}
    <div class="card">
        <div class="card-body">
            <p>
                Eligibility rules are set per listing by the provider who posts it, and they are not
                weighted. An applicant either meets one or does not.
            </p>

            <ul class="mb-3">
                <li>Minimum ZIMSEC A-Level points</li>
                <li>Required subjects at a stated grade, under a stated qualification</li>
                <li>Maximum age</li>
                <li>Required province, locality or settlement type</li>
                <li>A required results certificate</li>
            </ul>

            <p class="mb-0">
                An applicant who fails any rule the listing states is shown as ineligible and scores
                nothing at all - not a low percentage, but zero - whatever the weights above say. Nothing
                on this page can change that, and nothing on this page can make an eligible applicant
                ineligible. The two questions are answered separately, and eligibility is answered first.
            </p>
        </div>
    </div>

@endsection
