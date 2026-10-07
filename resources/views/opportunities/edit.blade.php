@extends('layouts.app')

@section('title', 'Edit scholarship')

@section('content')

    @php
        $impact = \App\Support\EditImpact::untouched($opportunity->moderation_status);
    @endphp

    <x-page-header title="Edit scholarship"
                   subtitle="Some changes take a live listing offline for review and some do not. The notice above the Save button tells you which, before you save."
                   eyebrow="Provider" />

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('opportunities.update', $opportunity->opportunity_id) }}" novalidate>
                @csrf
                @method('PUT')

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">The offer</h2>
                    </div>
                    <div class="card-body">
                        <x-form.input name="title" label="Scholarship title" required
                                      :value="$opportunity->title"
                                      hint="For example: Zimplats Engineering Undergraduate Bursary 2026." />

                        <x-form.input name="provider_display_name" label="Awarding on behalf of another organisation (optional)"
                                      :value="$opportunity->on_behalf_of"
                                      hint="Leave blank to publish as your own organisation. Fill it in only if you are awarding for another organisation: it is then shown publicly as &quot;Posted by [your organisation] on behalf of [this name]&quot; and an administrator checks it before it goes live." />

                        <x-form.textarea name="description" label="Full description" :rows="8" required
                                         :value="$opportunity->description"
                                         hint="Cover what the award pays for, who it is aimed at, and what applicants must submit." />
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Eligibility and deadline</h2>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <x-form.select name="education_level" label="Who this scholarship is for"
                                               :options="$educationLevels" :grouped="true"
                                               :value="$opportunity->education_level"
                                               placeholder="Any level"
                                               hint="Form 1 is a transition award for pupils finishing Primary school." />
                            </div>
                            <div class="col-md-6">
                                <x-form.select name="minimum_education_level" label="Minimum qualifying level (optional)"
                                               :options="$minimumLevels" :grouped="true"
                                               :value="$opportunity->minimum_education_level"
                                               placeholder="Whatever the level above allows"
                                               hint="Only set this if your listing is stricter than the general pathway - for example, an Undergraduate award that requires A-Level rather than accepting O-Level applicants directly." />
                            </div>
                            <div class="col-md-6">
                                <x-form.select name="country" label="Country where it is held"
                                               :options="$countries" :value="$opportunity->country ?: \App\Support\FormOptions::DEFAULT_COUNTRY"
                                               :placeholder="null"
                                               hint="Where the student will study. Defaults to Zimbabwe." />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="target_field" label="Field of study"
                                              :value="$opportunity->target_field"
                                              list="field-list"
                                              hint="Leave blank to accept any field." />
                                <datalist id="field-list">
                                    @foreach(array_unique(array_merge($fields, $targetFieldSuggestions)) as $field)
                                        <option value="{{ $field }}"></option>
                                    @endforeach
                                </datalist>
                            </div>
                            <div class="col-md-3">
                                <x-form.select name="funding_type" label="Funding type"
                                               :options="$fundingTypes" :value="$opportunity->funding_type"
                                               placeholder="Not specified" />
                            </div>
                            <div class="col-md-3">
                                <x-form.input name="deadline" label="Application deadline" type="date"
                                              :value="$opportunity->deadline?->format('Y-m-d')"
                                              min="{{ ($opportunity->deadline && $opportunity->deadline->isPast() ? $opportunity->deadline : now())->toDateString() }}"
                                              hint="Leave blank for a rolling intake." />
                            </div>
                        </div>
                    </div>
                </div>

                @include('opportunities.partials.award-fields', ['opportunity' => $opportunity])

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Reason for this change</h2>
                    </div>
                    <div class="card-body">
                        <x-form.textarea name="reason" label="What changed and why" :rows="3" required
                                         hint="Shown in the review trail for transparency, e.g. &quot;Corrected eligibility criteria&quot; or &quot;Updated award amount&quot;." />
                    </div>
                </div>

                <div id="edit-impact"
                     data-url="{{ route('opportunities.editImpact', $opportunity->opportunity_id) }}"
                     class="alert alert-{{ $impact['tone'] }} mb-4"
                     role="status" aria-live="polite">
                    <div class="fw-semibold" data-impact-headline>{{ $impact['headline'] }}</div>
                    <div class="small" data-impact-detail>{{ $impact['detail'] }}</div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <x-submit-button :label="$impact['button']" size="lg" busy-label="Saving..." />
                    <a class="btn btn-outline-secondary btn-lg" href="{{ route('provider.dashboard') }}">Cancel</a>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">What happens next</h2>
                </div>
                <div class="card-body">
                    <p class="small text-secondary mb-0">
                        Changes to what applicants rely on - the title, description, level, field, funding,
                        country, location, award, eligibility rules or subject requirements - take a live listing
                        offline until an administrator approves it again. Smaller edits, such as the application
                        link, do not. It stays visible in your dashboard either way. If you
                        only need to push the deadline back, use "Extend deadline" from the dashboard instead - it
                        does not require re-review.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
