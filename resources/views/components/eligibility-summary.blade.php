@props([
    'fit',
    /**
     * 'full'    the alert, every requirement, the advisory notes - for a page
     *           where the reader is deciding whether to apply.
     * 'compact' the verdict and a count, with the same list one click away -
     *           for a card in a list of many.
     */
    'variant' => 'full',
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
     * was asked" are different facts, and an applicant reading a bare listing
     * was previously shown neither - only a score, with the absence of a
     * requirements block as the sole hint. This never renders a tick for a
     * requirement nobody set.
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
    $rules = \App\Services\ScholarFit\RequirementOutcome::rules($fit->breakdown->requirementOutcomes);
    $notes = $fit->breakdown->advisoryNotes();
    $eligible = $fit->meetsRequirements();
    $stated = $rules !== [];
    $met = count(array_filter($rules, static fn ($o) => $o->passed));
    $unmet = count($rules) - $met;
@endphp

@if($variant === 'compact')
    <div {{ $attributes->merge(['class' => 'd-flex flex-wrap align-items-center gap-2']) }}>
        @if(! $stated)
            <x-status-badge label="No stated requirements" tone="secondary" icon="shield" />
            <span class="small text-secondary">The provider decides who is awarded.</span>
        @elseif($eligible)
            <x-status-badge label="Eligible" tone="success" />
            <span class="small text-secondary">
                You meet {{ $met === 1 ? 'the one requirement' : 'all ' . $met . ' requirements' }} this scholarship states.
            </span>
        @else
            <x-status-badge label="Not eligible" tone="danger" />
            <span class="small text-secondary">
                {{ $unmet }} {{ \Illuminate\Support\Str::plural('requirement', $unmet) }} not met.
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
                            <x-icon :name="$outcome->passed ? 'check-circle' : 'x-circle'" :size="14"
                                    class="flex-shrink-0 mt-1 {{ $outcome->passed ? 'text-success' : 'text-danger' }}" />
                            <span class="{{ $outcome->passed ? 'text-secondary' : '' }}">{{ $outcome->message }}</span>
                        </li>
                    @endforeach
                </ul>
            </details>
        @endif
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
                </p>

                <div class="small fw-semibold mb-1">{{ $eligible ? 'Why you qualify' : 'Why you do not qualify' }}</div>

                {{-- Met and unmet together, in the order they were evaluated, so a
                     refusal reads as a verdict on the one or two things that fell
                     short rather than on the whole profile. Each line carries what
                     was required and what the applicant has. --}}
                <ul class="list-unstyled d-grid gap-1 mb-0 small">
                    @foreach($rules as $outcome)
                        <li class="d-flex gap-2 align-items-start">
                            <x-icon :name="$outcome->passed ? 'check-circle' : 'x-circle'" :size="14"
                                    class="flex-shrink-0 mt-1 {{ $outcome->passed ? 'text-success' : 'text-danger' }}" />
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
@else
    {{--
        Informational, deliberately not a verdict. It says what the provider did
        not state; it does not say the applicant passed anything, because
        nothing was asked of them.
    --}}
    <div {{ $attributes->merge(['class' => 'alert alert-secondary mb-3']) }} role="note">
        <div class="d-flex gap-2 align-items-start">
            <x-icon name="shield" :size="20" class="flex-shrink-0 mt-1" />
            <div>
                <div class="fw-semibold mb-1">No entry requirements specified</div>
                <p class="small mb-0">
                    This scholarship does not specify entry requirements, so there is nothing for
                    ScholarZim to check your profile against. The provider decides who is awarded.
                </p>
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
