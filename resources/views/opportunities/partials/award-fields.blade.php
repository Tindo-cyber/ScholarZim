@props(['opportunity' => null, 'defaults' => []])

@php
    /**
     * Shared by the create and edit forms so a listing cannot end up with an
     * award value on one path and not the other.
     *
     * $opportunity is null when posting a new listing; every field then falls
     * back to old() and renders empty.
     */
    $value = static fn (string $field, $fallback = null) => $opportunity?->{$field} ?? ($defaults[$field] ?? $fallback);

    /*
     * Whether the "stricter rules" section starts open.
     *
     * It is folded away on a fresh form so the basics are what a provider sees, but
     * it must never hide something that matters: if any rule in it has a value
     * (stored, defaulted, or carried back from a failed save) or an error, it opens.
     * A rule that silently disqualifies applicants from behind a closed section is
     * the thing this must not become.
     */
    // The lowest maximum age anyone could meet at the level chosen above; level-form.js keeps it in step.
    $minAge = \App\Support\EducationLevel::minimumAge(old('education_level', $opportunity?->education_level));

    $advancedKeys = ['min_age', 'max_age', 'required_province', 'target_locality', 'target_settlement_type', 'min_academic_points', 'minimum_education_level'];

    $advancedOpen = collect($advancedKeys)->contains(fn ($key) => filled(old($key, $value($key))))
        || (bool) old('requires_results_certificate', $value('requires_results_certificate', false))
        || count((array) old('subject_requirements', $opportunity?->subjectRequirements?->all() ?? [])) > 0
        || collect($errors->keys())->contains(
            fn ($key) => in_array($key, array_merge($advancedKeys, ['requires_results_certificate']), true)
                || str_starts_with($key, 'subject_requirements')
        );
@endphp

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Award and deadline</h2>
    </div>
    <div class="card-body">
        <p class="text-secondary small">
            Students compare this first. A listing with no stated value still appears in search, but not in
            value sorting or "minimum award" filters.
        </p>

        <div class="row">
            <div class="col-md-6">
                <x-form.select name="funding_type" label="Funding type"
                               :options="$fundingTypes" :value="$opportunity?->funding_type"
                               placeholder="Not specified" />
            </div>
            <div class="col-md-6">
                <x-form.input name="deadline" label="Application deadline" type="date"
                              :value="$opportunity?->deadline?->format('Y-m-d')"
                              :min="($opportunity?->deadline && $opportunity->deadline->lte(\App\Support\Deadlines::earliest()) ? $opportunity->deadline : \App\Support\Deadlines::earliest())->toDateString()"
                              hint="Optional. Must be after today. Leave blank for rolling intake." />
            </div>
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
                <x-form.input name="award_slots" label="Number of awards" type="number" required
                              min="1" max="5000" step="1"
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
        <h2 class="h6 fw-semibold mb-0">Other conditions (optional)</h2>
    </div>
    <div class="card-body">
        <x-form.textarea name="other_conditions" label="Conditions students must check for themselves" :rows="3"
                         :value="$opportunity?->other_conditions"
                         hint="One a line, for example: IELTS 6.5 or higher / Two years of work experience / Return to Zimbabwe after studying. For a listing abroad, use this to specify what our field list cannot - 'MBBS or MBChB students only', 'must be accepted to a UK medical school'. Students see these as &quot;Also check yourself&quot;; they never decide whether a student qualifies." />
    </div>
</div>

{{--
    The stricter rules, folded away. Native <details>, so it opens and closes with
    JavaScript off, and the server decides whether it starts open ({{ '$advancedOpen' }}).
--}}
<details class="mb-4" id="advanced-rules" @if($advancedOpen) open @endif>
    <summary class="btn btn-outline-secondary mb-3">Add stricter rules (optional)</summary>

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">General eligibility</h2>
    </div>
    <div class="card-body">
        <div class="alert alert-warning d-flex gap-2" role="note">
            <x-icon name="shield" :size="18" class="flex-shrink-0 mt-1" />
            <div class="small">
                <strong>These are strict rules.</strong>
                A student who fails one is not eligible. Leave a rule blank unless it is a firm requirement.
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-form.select name="minimum_education_level" label="Minimum qualifying level"
                               :options="$minimumLevels" :grouped="true"
                               :value="$value('minimum_education_level')"
                               placeholder="No extra minimum"
                               hint="Only set this if your listing is stricter than the general pathway - for example, an Undergraduate award that requires A-Level rather than accepting O-Level applicants directly." />
            </div>
            <div class="col-md-6">
                <x-form.input name="min_age" label="Minimum age" type="number"
                              :min="\App\Support\EducationLevel::MINIMUM_APPLICANT_AGE" max="99" step="1"
                              :value="$value('min_age')"
                              hint="The youngest an applicant can be. Leave blank for no lower limit." />
            </div>
            <div class="col-md-6">
                <x-form.input name="max_age" label="Maximum age" type="number"
                              :min="$minAge" max="99" step="1"
                              :value="$value('max_age')" data-max-age
                              :hint="'The oldest an applicant can be. Leave blank for no upper limit.'" />
            </div>
            <div class="col-md-6">
                <x-form.select name="required_province" label="Student must live in this province"
                               :options="$provinces"
                               :value="$value('required_province')"
                               placeholder="No restriction" />
            </div>
            <div class="col-md-6">
                <x-form.input name="target_locality" label="Student must live in or near this town (optional)"
                              :value="$value('target_locality')"
                              placeholder="e.g. Gweru"
                              hint="Only for a place-specific award. Leave blank for a province-wide or nationwide one." />
            </div>
            <div class="col-md-6">
                <x-form.select name="target_settlement_type" label="Student must come from this kind of area (optional)"
                               :options="$settlementTypes"
                               :value="$value('target_settlement_type')"
                               placeholder="No restriction" />
            </div>
            <div class="col-12" data-level-needs="certificate">
                <x-form.checkbox name="requires_results_certificate" wrapper-class="mb-0"
                                 label="Proof of academic results must be on file before applying"
                                 hint="Grade 7 results slip, O/A-Level results certificate or transcript, depending on the level."
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
        <div data-level-needs="subjects">
        <h3 class="sz-eyebrow">Subject requirements</h3>

        <p class="text-secondary small">
            List subjects applicants must hold at a minimum grade. Leave empty for none.
        </p>
        <p class="text-secondary small">
            Grades are compared under the qualification you choose. A Cambridge A Level result is never
            converted into a ZIMSEC grade, so state the requirement under the right board.
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
                    @php
                        /*
                         * Which rows to draw.
                         *
                         * After a failed save the form is rendered again with old(), and the
                         * rows must come from what the provider just submitted - drawing them
                         * only from the stored requirements dropped every row they had added
                         * (a create form has none stored) and silently reverted every change
                         * they had made on an edit. Stored rows are the fallback for a page
                         * with no old input.
                         *
                         * The controller renumbers submitted rows 0..n-1, so $idx is both the
                         * form key and the row's position on screen.
                         */
                        $submittedRows = old('subject_requirements');
                        $requirementRows = [];

                        if (is_array($submittedRows)) {
                            foreach (array_values($submittedRows) as $submitted) {
                                $submitted = is_array($submitted) ? $submitted : [];

                                $requirementRows[] = [
                                    'qualification_id' => $submitted['qualification_id'] ?? null,
                                    'subject_id' => $submitted['subject_id'] ?? null,
                                    'minimum_grade' => $submitted['minimum_grade'] ?? null,
                                    'stored' => null,
                                ];
                            }
                        } else {
                            foreach (($opportunity?->subjectRequirements ?? []) as $stored) {
                                $requirementRows[] = [
                                    'qualification_id' => $stored->qualification_id,
                                    'subject_id' => $stored->subject_id,
                                    'minimum_grade' => $stored->minimum_grade,
                                    'stored' => $stored,
                                ];
                            }
                        }
                    @endphp

                    @foreach($requirementRows as $idx => $row)
                        @php
                            $rowQualification = $qualifications->firstWhere('id', (int) $row['qualification_id']);
                            $rowSubjects = $rowQualification?->activeSubjects ?? collect();
                            $rowSubject = $rowSubjects->firstWhere('id', (int) $row['subject_id']) ?? $row['stored']?->subject;
                            $rowGrades = $rowSubject?->grades() ?? $rowQualification?->grades() ?? [];

                            // Each message already says "Row N: ..." and sits with the field it is
                            // about, so a provider is not matched up with a path like
                            // subject_requirements.1.qualification_id.
                            $qualificationError = $errors->first("subject_requirements.$idx.qualification_id");
                            $subjectError = $errors->first("subject_requirements.$idx.subject_id");
                            $gradeError = $errors->first("subject_requirements.$idx.minimum_grade");
                        @endphp
                        <tr class="subject-requirement-row">
                            <td data-label="Qualification">
                                <select class="form-select form-select-sm qualification-select @if($qualificationError) is-invalid @endif"
                                        name="subject_requirements[{{ $idx }}][qualification_id]"
                                        aria-label="Qualification">
                                    @unless($rowQualification)
                                        <option value="" selected>Select qualification</option>
                                    @endunless
                                    @foreach($qualifications as $qual)
                                        <option value="{{ $qual->id }}"
                                            @selected($qual->id == $row['qualification_id'])>{{ $qual->name }}</option>
                                    @endforeach
                                </select>
                                @if($qualificationError)
                                    <div class="invalid-feedback d-block">{{ $qualificationError }}</div>
                                @endif
                            </td>
                            {{--
                                Rows render their options server-side, already selected.
                                The script below re-populates them when the qualification changes,
                                but the form is correct and submittable before it runs - a row whose
                                options only exist once JavaScript has populated them submits an
                                empty grade without it, which is the same data loss this whole
                                section was fixed to prevent.
                            --}}
                            <td data-label="Subject">
                                <select class="form-select form-select-sm subject-select @if($subjectError) is-invalid @endif"
                                        name="subject_requirements[{{ $idx }}][subject_id]"
                                        data-selected="{{ $row['subject_id'] }}"
                                        aria-label="Subject">
                                    <option value="">Select subject</option>
                                    @foreach($rowSubjects as $subject)
                                        <option value="{{ $subject->id }}"
                                            @selected($subject->id == $row['subject_id'])>{{ $subject->label() }}</option>
                                    @endforeach
                                </select>
                                @if($subjectError)
                                    <div class="invalid-feedback d-block">{{ $subjectError }}</div>
                                @endif
                            </td>
                            <td data-label="Minimum grade">
                                {{-- This field previously rendered readonly and unnamed, so it was
                                     never submitted and every edit reset the rule to "any grade". --}}
                                <select class="form-select form-select-sm grade-select @if($gradeError) is-invalid @endif"
                                        name="subject_requirements[{{ $idx }}][minimum_grade]"
                                        data-selected="{{ $row['minimum_grade'] }}"
                                        aria-label="Minimum grade">
                                    <option value="">Any grade</option>
                                    @foreach($rowGrades as $grade)
                                        <option value="{{ $grade }}"
                                            @selected((string) $grade === (string) $row['minimum_grade'])>{{ $grade }}</option>
                                    @endforeach
                                </select>
                                @if($gradeError)
                                    <div class="invalid-feedback d-block">{{ $gradeError }}</div>
                                @endif
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
        </div>

        <div data-level-needs="points">
        <h3 class="sz-eyebrow">Total A-Level points</h3>

        <p class="text-secondary small">
            Checked separately from the subjects above: do the applicant's ZIMSEC A-Level grades add up
            to this total, whatever the subjects?
        </p>

        <div class="row">
            <div class="col-md-6">
                <x-form.input name="min_academic_points" label="Minimum ZIMSEC A-Level points" type="number"
                              min="1" :max="\App\Support\Academic\AcademicCatalogue::maxZimsecALevelPoints()" step="1"
                              :value="$value('min_academic_points')"
                              hint="ZIMSEC A-Level only: A=5, B=4, C=3, D=2, E=1." />
            </div>
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
</details>
