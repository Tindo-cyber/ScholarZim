@props(['opportunity' => null])

@php
    /**
     * Shared by the create and edit forms so a listing cannot end up with an
     * award value on one path and not the other.
     *
     * $opportunity is null when posting a new listing; every field then falls
     * back to old() and renders empty.
     */
    $value = static fn (string $field, $fallback = null) => $opportunity?->{$field} ?? $fallback;
@endphp

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Funding and award</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary small">
            This is the first thing a student compares. A listing with no stated value still appears in
            search, but it is excluded from value sorting and from any "minimum award" filter.
        </p>

        <div class="row">
            <div class="col-md-4">
                <x-form.input name="award_amount" label="Award value" type="number"
                              min="0" step="0.01"
                              :value="$value('award_amount')"
                              hint="Per award, per year. Leave blank if it varies." />
            </div>
            <div class="col-md-4">
                <x-form.select name="award_currency" label="Currency"
                               :options="$currencies"
                               :value="$value('award_currency', $defaultCurrency)"
                               :placeholder="null" />
            </div>
            <div class="col-md-4">
                <x-form.input name="award_slots" label="Number of awards" type="number"
                              min="1" step="1"
                              :value="$value('award_slots')"
                              hint="How many students will be funded." />
            </div>

            <div class="col-md-8">
                <x-form.input name="external_url" label="Your own application page" type="url"
                              :value="$value('external_url')"
                              placeholder="https://"
                              hint="Optional. Shown alongside the ScholarZim application." />
            </div>

            <div class="col-md-4 d-flex align-items-center">
                <x-form.checkbox name="is_renewable" label="Renewable each year"
                                 :checked="(bool) $value('is_renewable', false)" />
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">General eligibility</h2>
    </div>
    <div class="card-body">
        <div class="alert alert-warning d-flex gap-2" role="note">
            <x-icon name="shield" :size="18" class="flex-shrink-0 mt-1" />
            <div class="small">
                <strong>These disqualify, they do not merely score down.</strong>
                A student who fails one of these is told they are not eligible and the listing is left out
                of their recommendations. Leave a rule blank unless it is genuinely a rule - everything
                else belongs in the description, where it guides rather than blocks.
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-form.input name="max_age" label="Maximum age" type="number"
                              min="10" max="99" step="1"
                              :value="$value('max_age')"
                              hint="Only applied when the student has given a date of birth." />
            </div>
            <div class="col-md-6">
                <x-form.select name="required_province" label="Required province"
                               :options="$provinces"
                               :value="$value('required_province')"
                               placeholder="No restriction" />
            </div>
            <div class="col-md-6">
                <x-form.input name="target_locality" label="Target locality (optional)"
                              :value="$value('target_locality')"
                              placeholder="e.g. Gweru"
                              hint="Only set this for a place-specific award. Leave blank for a province-wide or nationwide one - a blank locality on the student's own profile will never be treated as a match, so this never silently excludes students who simply haven't stated one." />
            </div>
            <div class="col-md-6">
                <x-form.select name="target_settlement_type" label="Target settlement type (optional)"
                               :options="$settlementTypes"
                               :value="$value('target_settlement_type')"
                               placeholder="No restriction" />
            </div>
            <div class="col-12">
                <x-form.checkbox name="requires_results_certificate" wrapper-class="mb-0"
                                 label="Proof of academic results must be on file before applying"
                                 hint="A results certificate for O/A-Level applicants, or a transcript for tertiary and postgraduate applicants."
                                 :checked="(bool) $value('requires_results_certificate', false)" />
            </div>
        </div>
    </div>
</div>


{{--
    Academic requirements, in two clearly separated halves.

    They are different rules and they are checked separately. A subject
    requirement asks "do you hold Mathematics at B or better?"; the points rule
    asks "do your ZIMSEC A-Level grades add up to 15?". An applicant can pass
    one and fail the other, and the engine reports them as two outcomes.

    The points field used to sit in the general eligibility card between a
    maximum age and a required province, which invited reading it as one more
    demographic filter. Nothing about either rule changed - only which heading
    they sit under.
--}}
<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Academic requirements (optional)</h2>
    </div>
    <div class="card-body">
        <h3 class="sz-eyebrow">Subject requirements</h3>

        <p class="text-secondary small">
            If this award requires applicants to hold specific subjects at a minimum grade, list them here.
            Applicants who do not hold a required subject, or whose grade falls short, are told they are not
            eligible and exactly why. Leave empty to place no subject bar.
        </p>
        <p class="text-secondary small">
            Grades are compared under the qualification you choose. A Cambridge A Level result is never
            converted into a ZIMSEC grade, so state the requirement under the board you actually mean.
        </p>

        @if($errors->has('subject_requirements'))
            <div class="alert alert-danger small">{{ $errors->first('subject_requirements') }}</div>
        @endif

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2 sz-table-stack" id="subject-requirements-table">
                <thead>
                    <tr>
                        <th scope="col" style="width:34%">Qualification</th>
                        <th scope="col" style="width:34%">Subject</th>
                        <th scope="col" style="width:22%">Minimum grade</th>
                        <th scope="col" style="width:10%"><span class="visually-hidden">Actions</span></th>
                    </tr>
                </thead>
                <tbody id="subject-requirements-list">
                    @foreach(($opportunity?->subjectRequirements ?? []) as $idx => $existing)
                        <tr class="subject-requirement-row">
                            <td data-label="Qualification">
                                <select class="form-select form-select-sm qualification-select"
                                        name="subject_requirements[{{ $idx }}][qualification_id]"
                                        aria-label="Qualification">
                                    @foreach($qualifications as $qual)
                                        <option value="{{ $qual->id }}"
                                            @selected($qual->id == $existing->qualification_id)>{{ $qual->name }}</option>
                                    @endforeach
                                </select>
                            </td>
                            {{--
                                Existing rows render their options server-side, already selected.
                                The script below re-populates them when the qualification changes,
                                but the form is correct and submittable before it runs - a row whose
                                options only exist once JavaScript has populated them submits an
                                empty grade without it, which is the same data loss this whole
                                section was fixed to prevent.
                            --}}
                            <td data-label="Subject">
                                <select class="form-select form-select-sm subject-select"
                                        name="subject_requirements[{{ $idx }}][subject_id]"
                                        data-selected="{{ $existing->subject_id }}"
                                        aria-label="Subject">
                                    <option value="">Select subject</option>
                                    @foreach(($existing->qualification?->activeSubjects ?? []) as $subject)
                                        <option value="{{ $subject->id }}"
                                            @selected($subject->id == $existing->subject_id)>{{ $subject->label() }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td data-label="Minimum grade">
                                {{-- This field previously rendered readonly and unnamed, so it was
                                     never submitted and every edit reset the rule to "any grade". --}}
                                <select class="form-select form-select-sm grade-select"
                                        name="subject_requirements[{{ $idx }}][minimum_grade]"
                                        data-selected="{{ $existing->minimum_grade }}"
                                        aria-label="Minimum grade">
                                    <option value="">Any grade</option>
                                    @foreach(($existing->subject?->grades() ?? $existing->qualification?->grades() ?? []) as $grade)
                                        <option value="{{ $grade }}"
                                            @selected($grade === $existing->minimum_grade)>{{ $grade }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="text-end" data-label="">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-row">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <template id="subject-requirement-template">
            <tr class="subject-requirement-row">
                <td data-label="Qualification">
                    <select class="form-select form-select-sm qualification-select"
                            name="subject_requirements[__IDX__][qualification_id]" aria-label="Qualification">
                        <option value="">Select qualification</option>
                        @foreach($qualifications as $qual)
                            <option value="{{ $qual->id }}">{{ $qual->name }}</option>
                        @endforeach
                    </select>
                </td>
                <td data-label="Subject">
                    <select class="form-select form-select-sm subject-select"
                            name="subject_requirements[__IDX__][subject_id]" aria-label="Subject" disabled></select>
                </td>
                <td data-label="Minimum grade">
                    <select class="form-select form-select-sm grade-select"
                            name="subject_requirements[__IDX__][minimum_grade]" aria-label="Minimum grade" disabled></select>
                </td>
                <td class="text-end" data-label="">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row">Remove</button>
                </td>
            </tr>
        </template>

        <button type="button" id="add-subject-requirement" class="btn btn-sm btn-outline-secondary">
            Add a required subject
        </button>

        <hr class="my-4">

        <h3 class="sz-eyebrow">Total A-Level points</h3>

        <p class="text-secondary small">
            A separate rule from the subjects above, and checked separately. It asks whether the
            applicant's ZIMSEC A-Level grades add up to a total, whatever those subjects are.
        </p>

        <div class="row">
            <div class="col-md-6">
                <x-form.input name="min_academic_points" label="Minimum ZIMSEC A-Level points" type="number"
                              min="1" :max="\App\Support\Academic\AcademicCatalogue::maxZimsecALevelPoints()" step="1"
                              :value="$value('min_academic_points')"
                              hint="ZIMSEC A-Level only: A=5, B=4, C=3, D=2, E=1, so three A grades is 15. O-Level, Cambridge and degree results are never counted towards this." />
            </div>
        </div>

        {{--
            Built in the controller rather than inline: Blade's directive parser
            reads the argument to @json by bracket matching, and an arrow
            function returning an array inside it closes the directive early,
            which fails to compile the whole view.
        --}}
        <script type="application/json" id="subject-requirement-catalogue">@json($qualificationCatalogue)</script>

        {{--
            The behaviour that drives this grid lives in
            resources/js/subject-requirements.js, bundled by Vite and served
            from the application's own origin.

            It used to be an inline <script> right here, and the app's
            Content-Security-Policy is `script-src 'self'` - which an inline
            script is not. The browser refused to run it, so "Add a required
            subject" did nothing on either the create or the edit form. The
            policy was right and the markup was wrong; the markup moved.

            The JSON block above stays: a data block is not executed, so CSP
            has no opinion about it.
        --}}
    </div>
</div>
