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
    $checked = static fn (string $field) => (bool) old($field, $opportunity?->{$field} ?? false);
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
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="field-is_renewable"
                           name="is_renewable" value="1" @checked($checked('is_renewable'))>
                    <label class="form-check-label" for="field-is_renewable">
                        Renewable each year
                    </label>
                </div>
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
                <x-form.input name="min_academic_points" label="Minimum A-Level points" type="number"
                              min="1" max="60" step="1"
                              :value="$value('min_academic_points')"
                              hint="Only applied when the student states their points." />
            </div>
            <div class="col-md-6">
                <x-form.input name="max_age" label="Maximum age" type="number"
                              min="10" max="99" step="1"
                              :value="$value('max_age')"
                              hint="Only applied when the student has given a date of birth." />
            </div>
            <div class="col-md-6">
                <x-form.select name="required_citizenship" label="Required citizenship"
                               :options="$citizenships"
                               :value="$value('required_citizenship')"
                               placeholder="No restriction" />
            </div>
            <div class="col-md-6">
                <x-form.select name="required_province" label="Required province"
                               :options="$provinces"
                               :value="$value('required_province')"
                               placeholder="No restriction" />
            </div>
            <div class="col-12">
                <div class="form-check mb-0">
                    <input class="form-check-input" type="checkbox" id="field-requires_results_certificate"
                           name="requires_results_certificate" value="1"
                           @checked($checked('requires_results_certificate'))>
                    <label class="form-check-label" for="field-requires_results_certificate">
                        A results certificate must be on file before applying
                    </label>
                </div>
            </div>
        </div>
    </div>
</div>
