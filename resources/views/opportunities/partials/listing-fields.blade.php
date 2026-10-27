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
        <h2 class="h6 fw-semibold mb-0">Level and place of study</h2>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-6">
                <x-form.select name="education_level" label="Level of study"
                               :options="$educationLevels" :grouped="true"
                               :value="\App\Support\EducationLevel::canonical($opportunity?->education_level) ?? $opportunity?->education_level"
                               :placeholder="($levelsLimited ?? false) ? 'Choose a level' : 'Any level'"
                               :hint="'What the scholarship pays for. This decides which questions below apply.' . (($levelsLimited ?? false) ? ' Organisations registered outside Zimbabwe can fund Certificate, Diploma, Degree and postgraduate awards.' : '')" />
            </div>
            <div class="col-md-6">
                {{-- Asked here, before "Who is it open to?", because study abroad changes that card. --}}
                <x-form.select name="country" label="Country of study"
                               :options="$countries"
                               :value="$opportunity?->country ?: \App\Support\FormOptions::DEFAULT_COUNTRY"
                               :placeholder="null"
                               hint="Where the student will study. Defaults to Zimbabwe."
                               data-zimbabwe-only-hint="Form 1, O-Level and A-Level scholarships are for study in Zimbabwe." />
                {{-- Which levels fix the country to Zimbabwe: the same list the server enforces (EducationLevel::ZIMBABWE_ONLY_LEVELS). --}}
                <script type="application/json" id="zimbabwe-only-levels">@json(\App\Support\EducationLevel::ZIMBABWE_ONLY_LEVELS)</script>
            </div>
        </div>
    </div>
</div>

@include('opportunities.partials.scope-fields', ['opportunity' => $opportunity])

<div class="card mb-4">
    <div class="card-header">
        <h2 class="h6 fw-semibold mb-0">About the scholarship</h2>
    </div>
    <div class="card-body">
        <x-form.input name="title" label="Scholarship title" required
                      :value="$opportunity?->title"
                      hint="For example: Zimplats Engineering Undergraduate Bursary 2026." />

        <x-form.textarea name="description" label="Full description" :rows="8" required
                         :value="$opportunity?->description"
                         hint="Cover what the award pays for, who it is aimed at, and what applicants must submit. If it is for specific programmes, say so - 'for Medicine students' is read and checked automatically." />

        <x-form.input name="provider_display_name" label="Awarding on behalf of another organisation (optional)"
                      :value="$opportunity?->on_behalf_of"
                      hint="Leave blank to publish as your own organisation. If filled in, the listing shows &quot;Posted by [your organisation] on behalf of [this name]&quot; and an administrator checks it first." />
    </div>
</div>

@include('opportunities.partials.award-fields', ['opportunity' => $opportunity, 'defaults' => $defaults])

{{-- Built here rather than inline in the directive: see award-fields for why. --}}
<script type="application/json" id="level-capabilities">@json($levelCapabilities)</script>
<script type="application/json" id="level-min-ages">@json(\App\Support\OpportunityLevelRules::minimumMaxAges())</script>
