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
        <h2 class="h6 fw-semibold mb-0">What the award is worth</h2>
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
        <h2 class="h6 fw-semibold mb-0">Hard eligibility rules</h2>
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
                <x-form.input name="min_academic_points" label="Minimum ZIMSEC A-Level points" type="number"
                              min="1" :max="\App\Support\Academic\AcademicCatalogue::maxZimsecALevelPoints()" step="1"
                              :value="$value('min_academic_points')"
                              hint="ZIMSEC A-Level only: A=5, B=4, C=3, D=2, E=1, so three A grades is 15. O-Level, Cambridge and degree results are never counted towards this." />
            </div>
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


<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Required subjects (optional)</h2>
    </div>
    <div class="card-body">
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
            <table class="table table-sm align-middle mb-2" id="subject-requirements-table">
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
                            <td>
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
                            <td>
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
                            <td>
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
                            <td class="text-end">
                                <button type="button" class="btn btn-sm btn-outline-danger remove-row">Remove</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <template id="subject-requirement-template">
            <tr class="subject-requirement-row">
                <td>
                    <select class="form-select form-select-sm qualification-select"
                            name="subject_requirements[__IDX__][qualification_id]" aria-label="Qualification">
                        <option value="">Select qualification</option>
                        @foreach($qualifications as $qual)
                            <option value="{{ $qual->id }}">{{ $qual->name }}</option>
                        @endforeach
                    </select>
                </td>
                <td>
                    <select class="form-select form-select-sm subject-select"
                            name="subject_requirements[__IDX__][subject_id]" aria-label="Subject" disabled></select>
                </td>
                <td>
                    <select class="form-select form-select-sm grade-select"
                            name="subject_requirements[__IDX__][minimum_grade]" aria-label="Minimum grade" disabled></select>
                </td>
                <td class="text-end">
                    <button type="button" class="btn btn-sm btn-outline-danger remove-row">Remove</button>
                </td>
            </tr>
        </template>

        <button type="button" id="add-subject-requirement" class="btn btn-sm btn-outline-secondary">
            Add a required subject
        </button>

        {{--
            Built in the controller rather than inline: Blade's directive parser
            reads the argument to @json by bracket matching, and an arrow
            function returning an array inside it closes the directive early,
            which fails to compile the whole view.
        --}}
        <script type="application/json" id="subject-requirement-catalogue">@json($qualificationCatalogue)</script>

        <script>
            /**
             * Qualification drives subject and grade together. Both lists come
             * from the qualification rows themselves, so the form cannot offer
             * a grade the server would reject - the grades a board awards and
             * the grades this picker shows are the same list.
             */
            (function () {
                var listEl = document.getElementById('subject-requirements-list');
                var addButton = document.getElementById('add-subject-requirement');
                var templateEl = document.getElementById('subject-requirement-template');
                var catalogueEl = document.getElementById('subject-requirement-catalogue');
                if (!listEl || !addButton || !templateEl || !catalogueEl) return;

                var catalogue = JSON.parse(catalogueEl.textContent || '{}');
                var nextIdx = listEl.querySelectorAll('.subject-requirement-row').length;

                function fill(select, options, selected, blankLabel) {
                    select.innerHTML = '';
                    var blank = document.createElement('option');
                    blank.value = '';
                    blank.textContent = blankLabel;
                    select.appendChild(blank);

                    options.forEach(function (option) {
                        var el = document.createElement('option');
                        el.value = option.value;
                        el.textContent = option.label;
                        if (String(selected) === String(option.value)) el.selected = true;
                        select.appendChild(el);
                    });

                    select.disabled = options.length === 0;
                }

                function sync(row) {
                    var qualSelect = row.querySelector('.qualification-select');
                    var subjectSelect = row.querySelector('.subject-select');
                    var gradeSelect = row.querySelector('.grade-select');
                    if (!qualSelect || !subjectSelect || !gradeSelect) return;

                    var entry = catalogue[qualSelect.value] || { subjects: [], grades: [] };

                    fill(
                        subjectSelect,
                        entry.subjects.map(function (s) { return { value: s.id, label: s.name }; }),
                        subjectSelect.dataset.selected || subjectSelect.value,
                        'Select subject'
                    );

                    // The subject's own scale where it has one - the Cambridge
                    // IGCSE 9-1 syllabuses - otherwise the qualification's. The
                    // two are never offered together: a 9-1 syllabus cannot be
                    // given an A*-G bar, because the scales do not convert.
                    var subject = entry.subjects.filter(function (s) {
                        return String(s.id) === String(subjectSelect.value);
                    })[0];
                    var grades = (subject && subject.grades) ? subject.grades : entry.grades;

                    fill(
                        gradeSelect,
                        grades.map(function (g) { return { value: g, label: g }; }),
                        gradeSelect.dataset.selected || gradeSelect.value,
                        'Any grade'
                    );

                    // Cleared once populated, so re-syncing after a subject
                    // change reads the provider's actual choice rather than
                    // resetting to whatever the page loaded with.
                    delete subjectSelect.dataset.selected;
                    delete gradeSelect.dataset.selected;

                    // A blank grade is a valid choice - the subject is required
                    // but no bar is set on it - so the grade select stays usable
                    // even when no grades are configured.
                    gradeSelect.disabled = grades.length === 0;
                }

                function attach(row) {
                    var qualSelect = row.querySelector('.qualification-select');
                    if (qualSelect) {
                        qualSelect.addEventListener('change', function () {
                            var subjectSelect = row.querySelector('.subject-select');
                            var gradeSelect = row.querySelector('.grade-select');
                            if (subjectSelect) delete subjectSelect.dataset.selected;
                            if (gradeSelect) delete gradeSelect.dataset.selected;
                            sync(row);
                        });
                    }

                    // Changing the subject can change the scale it is graded
                    // on, so the grade list is rebuilt with it.
                    var subjectSelect = row.querySelector('.subject-select');
                    if (subjectSelect) {
                        subjectSelect.addEventListener('change', function () { sync(row); });
                    }

                    var removeButton = row.querySelector('.remove-row');
                    if (removeButton) {
                        removeButton.addEventListener('click', function () { row.remove(); });
                    }

                    sync(row);
                }

                addButton.addEventListener('click', function () {
                    var html = templateEl.innerHTML.replace(/__IDX__/g, String(nextIdx++));
                    var host = document.createElement('tbody');
                    host.innerHTML = html.trim();
                    var row = host.querySelector('.subject-requirement-row');
                    if (!row) return;
                    listEl.appendChild(row);
                    attach(row);
                });

                listEl.querySelectorAll('.subject-requirement-row').forEach(attach);
            })();
        </script>
    </div>
</div>
