@props([
    'fit',
    /**
     * 'full'    the alert, every requirement, the advisory notes - for a page
     *           where the reader is deciding whether to apply.
     * 'compact' the verdict and a count, with the same list one click away -
     *           for a card in a list of many.
     */
    'variant' => 'full',
    /**
     * 'student'  the person the result is about: a listing with no requirements reads "Eligible".
     * 'provider' someone reviewing an application: there it is only a fact about the listing, "no stated requirements".
     */
    'audience' => 'student',
])

@php
    /**
     * The eligibility half of a ScholarFit result, rendered apart from the score.
     *
     * Three cases, and the difference between them is the whole point of this
     * component:
     *
     *   - the listing states requirements and the applicant meets them all
     *   - the listing states requirements and some are unmet
     *   - the listing states no requirements at all
     *
     * The third is not the first. "Nothing was asked of you" and "you meet what
     * was asked" are different facts. A student is told they are eligible to
     * apply - nothing stands in the way, and the listing is open - together with
     * the plain statement that the provider gave no requirements; this never
     * renders a tick or "you meet" for a requirement nobody set. What the student's
     * profile holds does not change that. A closed listing is not called eligible.
     *
     * Every sentence comes from the RequirementOutcome objects the evaluator
     * produced, so what an applicant is told here is what the gate will say
     * when they press Submit. Advisory notes are kept out of the rule list and
     * marked differently: an unusual progression is not a requirement anyone
     * failed.
     *
     * The compact variant states the same verdict from the same data. It is a
     * second density, not a second opinion - which is why it lives here rather
     * than in a component of its own.
     */
    $rules = \App\Services\ScholarFit\RequirementOutcome::rules($fit->outcomes);
    $notes = $fit->advisoryNotes();
    $eligible = $fit->meetsRequirements();
    $open = $fit->listingIsOpen();
    $forProvider = $audience === 'provider';
    // A rule the profile cannot answer is neither met nor unmet: it is its own state,
    // shown as a question with what to add, never as a tick or a cross.
    $pending = $fit->needsInformation();
    // Nothing missing from the profile, but the listing's own wording could not be read: a review, not a question.
    $review = $fit->needsReview();
    $missing = $fit->missingFields();
    $stated = $rules !== [];
    $met = count(array_filter($rules, static fn ($o) => $o->passed && ! $o->needsInformation));
    $unmet = count(\App\Services\ScholarFit\RequirementOutcome::failures($rules));
@endphp

@if($variant === 'compact')
    <div {{ $attributes->merge(['class' => 'd-flex flex-wrap align-items-center gap-2']) }}>
        @if($review)
            <x-status-badge label="Needs review" tone="warning" icon="shield" />
            <span class="small text-secondary">The listing's education wording could not be read with confidence. Check it with the provider.</span>
        @elseif($pending)
            <x-status-badge label="Needs information" tone="warning" icon="shield" />
            <span class="small text-secondary">Add your {{ implode(' and ', $missing) }} to check this one.</span>
        @elseif(! $stated && $forProvider)
            <x-status-badge label="No stated requirements" tone="secondary" icon="shield" />
            <span class="small text-secondary">The provider decides who is awarded.</span>
        @elseif(! $stated && ! $open)
            <x-status-badge label="Not open" tone="secondary" icon="shield" />
            <span class="small text-secondary">This scholarship is no longer open for applications.</span>
        @elseif(! $stated)
            <x-status-badge label="Eligible" tone="success" />
            <span class="small text-secondary">No specific requirements were provided. You appear eligible to apply.</span>
        @elseif($eligible)
            <x-status-badge label="Eligible" tone="success" />
            <span class="small text-secondary">
                You meet {{ $met === 1 ? 'the one requirement' : 'all ' . $met . ' requirements' }} this scholarship states.
            </span>
        @else
            <x-status-badge label="Not eligible" tone="danger" />
            <span class="small text-secondary">
                {{ $fit->failsEducationRequirement() ? \App\Services\ScholarFit\EligibilityResult::EDUCATION_NOT_MET : $unmet . ' ' . \Illuminate\Support\Str::plural('requirement', $unmet) . ' not met.' }}
            </span>
        @endif

        @if($stated)
            <details class="small w-100 mt-1">
                <summary class="text-secondary">
                    {{ $eligible ? 'Why you qualify' : 'Why you do not qualify' }}
                </summary>
                <ul class="list-unstyled d-grid gap-1 mt-2 mb-0">
                    @foreach($rules as $outcome)
                        <li class="d-flex gap-2 align-items-start">
                            <x-icon :name="$outcome->needsInformation ? 'circle' : ($outcome->passed ? 'check-circle' : 'x-circle')" :size="14"
                                    class="flex-shrink-0 mt-1 {{ $outcome->needsInformation ? 'text-warning' : ($outcome->passed ? 'text-success' : 'text-danger') }}" />
                            <span class="{{ $outcome->passed ? 'text-secondary' : '' }}">{{ $outcome->message }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
    </div>
@elseif($review)
    {{-- The listing says something about education that ScholarZim cannot read reliably. It is neither called open to
         everyone nor used to refuse anyone: the reader is told exactly what was found and who can settle it. --}}
    <div {{ $attributes->merge(['class' => 'alert mb-3 alert-warning']) }} role="alert">
        <div class="d-flex gap-2 align-items-start">
            <x-icon name="shield" :size="20" class="flex-shrink-0 mt-1" />
            <div class="w-100">
                <div class="fw-semibold mb-1">NEEDS REVIEW</div>

                <p class="small mb-2">
                    We cannot confirm whether you qualify: part of this listing's wording describes who it is for, and ScholarZim could not read it reliably.
                </p>

                <ul class="list-unstyled d-grid gap-1 mb-0 small">
                    @foreach(\App\Services\ScholarFit\RequirementOutcome::pending($fit->outcomes) as $outcome)
                        <li class="d-flex gap-2 align-items-start">
                            <x-icon name="circle" :size="14" class="flex-shrink-0 mt-1 text-warning" />
                            <span>{{ $outcome->message }}</span>
                        </li>
                    @endforeach
                    @foreach(\App\Services\ScholarFit\RequirementOutcome::passes($fit->outcomes) as $outcome)
                        <li class="d-flex gap-2 align-items-start">
                            <x-icon name="check-circle" :size="14" class="flex-shrink-0 mt-1 text-success" />
                            <span class="text-secondary">{{ $outcome->message }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@elseif($pending)
    <div {{ $attributes->merge(['class' => 'alert mb-3 alert-warning']) }} role="alert">
        <div class="d-flex gap-2 align-items-start">
            <x-icon name="shield" :size="20" class="flex-shrink-0 mt-1" />
            <div class="w-100">
                <div class="fw-semibold mb-1">NEEDS INFORMATION</div>

                <p class="small mb-2">
                    We cannot say yet whether you qualify: your profile does not answer
                    {{ count($missing) === 1 ? 'one thing' : 'some things' }} this scholarship asks.
                </p>

                <ul class="list-unstyled d-grid gap-1 mb-0 small">
                    @foreach($rules as $outcome)
                        <li class="d-flex gap-2 align-items-start">
                            <x-icon :name="$outcome->needsInformation ? 'circle' : 'check-circle'" :size="14"
                                    class="flex-shrink-0 mt-1 {{ $outcome->needsInformation ? 'text-warning' : 'text-success' }}" />
                            <span class="{{ $outcome->needsInformation ? '' : 'text-secondary' }}">{{ $outcome->message }}</span>
                        </li>
                    @endforeach
                </ul>

                <p class="small mb-0 mt-2">
                    <a href="{{ route('applicant.profile') }}">Add your {{ implode(' and ', $missing) }}</a>
                    to your profile and we will check it.
                </p>
            </div>
        </div>
    </div>
@elseif($stated)
    <div {{ $attributes->merge(['class' => 'alert mb-3 alert-' . ($eligible ? 'success' : 'danger')]) }} role="alert">
        <div class="d-flex gap-2 align-items-start">
            <x-icon :name="$eligible ? 'check-circle' : 'x-circle'" :size="20" class="flex-shrink-0 mt-1" />
            <div class="w-100">
                <div class="fw-semibold mb-1">{{ $eligible ? 'ELIGIBLE' : 'NOT ELIGIBLE' }}</div>

                <p class="small mb-2">
                    {{ $eligible
                        ? 'You meet every requirement this scholarship states.'
                        : 'This scholarship states requirements your profile does not meet.' }}
                    @if(! $eligible && $fit->failsEducationRequirement())
                        {{ \App\Services\ScholarFit\EligibilityResult::EDUCATION_NOT_MET }}
                    @endif
                </p>

                <div class="small fw-semibold mb-1">{{ $eligible ? 'Why you qualify' : 'Why you do not qualify' }}</div>

                {{-- Met and unmet together, in the order they were evaluated, so a
                     refusal reads as a verdict on the one or two things that fell
                     short rather than on the whole profile. Each line carries what
                     was required and what the applicant has. --}}
                <ul class="list-unstyled d-grid gap-1 mb-0 small">
                    @foreach($rules as $outcome)
                        <li class="d-flex gap-2 align-items-start">
                            <x-icon :name="$outcome->needsInformation ? 'circle' : ($outcome->passed ? 'check-circle' : 'x-circle')" :size="14"
                                    class="flex-shrink-0 mt-1 {{ $outcome->needsInformation ? 'text-warning' : ($outcome->passed ? 'text-success' : 'text-danger') }}" />
                            <span class="{{ $outcome->passed ? 'text-secondary' : '' }}">{{ $outcome->message }}</span>
                        </li>
                    @endforeach
                </ul>

                @unless($eligible)
                    <p class="small mb-0 mt-2">
                        <a href="{{ route('applicant.profile') }}">Update your profile</a>
                        if any of these are out of date.
                    </p>
                @endunless
            </div>
        </div>
    </div>
@elseif($forProvider)
    {{-- A reviewer's view of a listing that set no requirement: a fact about the listing, not a verdict. --}}
    <div {{ $attributes->merge(['class' => 'alert alert-secondary mb-3']) }} role="note">
        <div class="d-flex gap-2 align-items-start">
            <x-icon name="shield" :size="20" class="flex-shrink-0 mt-1" />
            <div>
                <div class="fw-semibold mb-1">No entry requirements specified</div>
                <p class="small mb-0">This scholarship does not specify entry requirements. The provider decides who is awarded.</p>
            </div>
        </div>
    </div>
@elseif(! $open)
    {{-- No requirements, but nothing can be applied for: say so rather than call anyone eligible. --}}
    <div {{ $attributes->merge(['class' => 'alert alert-secondary mb-3']) }} role="note">
        <div class="d-flex gap-2 align-items-start">
            <x-icon name="shield" :size="20" class="flex-shrink-0 mt-1" />
            <div>
                <div class="fw-semibold mb-1">Not open for applications</div>
                <p class="small mb-0">This scholarship states no entry requirements, but it is no longer open for applications.</p>
            </div>
        </div>
    </div>
@else
    {{--
        Eligible to apply, and honestly so: nothing was asked, so nothing is ticked and
        nothing is said to be met. The profile does not change this (there is nothing to
        check it against); it is only needed to submit, which is the application's own gate.
    --}}
    <div {{ $attributes->merge(['class' => 'alert mb-3 alert-success']) }} role="alert">
        <div class="d-flex gap-2 align-items-start">
            <x-icon name="check-circle" :size="20" class="flex-shrink-0 mt-1" />
            <div>
                <div class="fw-semibold mb-1">ELIGIBLE</div>
                <p class="small mb-0">{{ \App\Services\ScholarFit\EligibilityResult::NO_REQUIREMENTS_MESSAGE }}</p>
                @unless($fit->profileComplete)
                    <p class="small mb-0 mt-2">
                        <a href="{{ route('applicant.profile') }}">Complete your profile</a>
                        before you submit: an application needs it, whatever the scholarship asks.
                    </p>
                @endunless
            </div>
        </div>
    </div>
@endif

@foreach($notes as $note)
    <p class="small text-secondary d-flex gap-2 align-items-start">
        <x-icon name="shield" :size="16" class="flex-shrink-0 mt-1" />
        <span>{{ \App\Support\ScholarFitCopy::humanise($note->message) }}</span>
    </p>
@endforeach
