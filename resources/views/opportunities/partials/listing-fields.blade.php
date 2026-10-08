@props(['opportunity' => null, 'defaults' => []])

@php
    /**
     * The whole listing form, shared by create, edit and duplicate, so a field
     * cannot exist on one path and not another.
     *
     * $opportunity is null for a new listing, a stored listing when editing, and an
     * unsaved copy of one when duplicating. $defaults is what a new listing starts
     * with (see ListingDefaults); a stored value always beats a default, and
     * old() beats both, which the field components handle themselves.
     *
     * ORDER IS THE POINT. The level a scholarship is for decides which of the rest
     * apply, so it is asked first. Everything below it is on the page whatever the
     * level - nothing is hidden by the server - and resources/js/level-form.js only
     * tidies away what the chosen level does not use. With JavaScript off the form
     * shows everything and still works, and the Phase 2 rules report any
     * combination that cannot be true.
     */
    $v = static fn (string $field, $fallback = null) => $opportunity?->{$field} ?? ($defaults[$field] ?? $fallback);

    $levelCapabilities = [];
    foreach (array_merge([''], \App\Support\EducationLevel::TARGET_LEVELS) as $level) {
        $levelCapabilities[$level] = \App\Support\OpportunityLevelRules::capabilities($level === '' ? null : $level);
    }
@endphp

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">Who is this scholarship for?</h2>
    </div>
    <div class="card-body">
        <x-form.select name="education_level" label="Level of study"
                       :options="$educationLevels" :grouped="true"
                       :value="\App\Support\EducationLevel::canonical($opportunity?->education_level) ?? $opportunity?->education_level"
                       placeholder="Any level"
                       hint="This decides which of the questions below apply. A Zimbabwean degree programme such as BSc Honours or BCom Honours belongs under Undergraduate; a one-year Honours after a degree (as in South Africa) belongs under Postgraduate. Form 1 is a transition award for pupils finishing Primary school." />
    </div>
</div>

@include('opportunities.partials.scope-fields', ['opportunity' => $opportunity])

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">The offer</h2>
    </div>
    <div class="card-body">
        <x-form.input name="title" label="Scholarship title" required
                      :value="$opportunity?->title"
                      hint="For example: Zimplats Engineering Undergraduate Bursary 2026." />

        <div class="row">
            <div class="col-md-6" data-level-needs="field">
                <x-form.input name="target_field" label="Field of study"
                              :value="$opportunity?->target_field"
                              list="field-list"
                              hint="Leave blank to accept any field." />
                <datalist id="field-list">
                    @foreach(array_unique(array_merge($fields, $targetFieldSuggestions)) as $field)
                        <option value="{{ $field }}"></option>
                    @endforeach
                </datalist>
            </div>
            <div class="col-md-6">
                <x-form.input name="deadline" label="Application deadline" type="date"
                              :value="$opportunity?->deadline?->format('Y-m-d')"
                              min="{{ ($opportunity?->deadline && $opportunity->deadline->isPast() ? $opportunity->deadline : now())->toDateString() }}"
                              hint="Leave blank for a rolling intake." />
            </div>
            <div class="col-md-6">
                <x-form.select name="funding_type" label="Funding type"
                               :options="$fundingTypes" :value="$opportunity?->funding_type"
                               placeholder="Not specified" />
            </div>
            <div class="col-md-6">
                <x-form.select name="country" label="Country where it is held"
                               :options="$countries"
                               :value="$opportunity?->country ?: \App\Support\FormOptions::DEFAULT_COUNTRY"
                               :placeholder="null"
                               hint="Where the student will study. Defaults to Zimbabwe." />
            </div>
        </div>

        <x-form.textarea name="description" label="Full description" :rows="8" required
                         :value="$opportunity?->description"
                         hint="Cover what the award pays for, who it is aimed at, and what applicants must submit." />

        <x-form.input name="provider_display_name" label="Awarding on behalf of another organisation (optional)"
                      :value="$opportunity?->on_behalf_of"
                      hint="Leave blank to publish as your own organisation. Fill it in only if you are awarding for another organisation: it is then shown publicly as &quot;Posted by [your organisation] on behalf of [this name]&quot; and an administrator checks it before it goes live." />
    </div>
</div>

@include('opportunities.partials.award-fields', ['opportunity' => $opportunity, 'defaults' => $defaults])

{{-- Built here rather than inline in the directive: see award-fields for why. --}}
<script type="application/json" id="level-capabilities">@json($levelCapabilities)</script>
