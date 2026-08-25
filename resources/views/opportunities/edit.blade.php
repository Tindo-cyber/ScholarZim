@extends('layouts.app')

@section('title', 'Edit scholarship')

@section('content')

    <x-page-header title="Edit scholarship"
                   subtitle="Changing the details sends this listing back for administrator review before it is public again."
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

                        <x-form.input name="provider_display_name" label="Awarding body"
                                      :value="$opportunity->provider_name"
                                      list="awarding-body-list"
                                      hint="Shown publicly. Defaults to your organisation name." />
                        <datalist id="awarding-body-list">
                            @foreach($awardingBodySuggestions as $suggestion)
                                <option value="{{ $suggestion }}"></option>
                            @endforeach
                        </datalist>

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
                                <x-form.select name="education_level" label="Education level"
                                               :options="$educationLevels" :grouped="true"
                                               :value="$opportunity->education_level"
                                               placeholder="Any level" />
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
                            <div class="col-md-4">
                                <x-form.select name="funding_type" label="Funding type"
                                               :options="$fundingTypes" :value="$opportunity->funding_type"
                                               placeholder="Not specified" />
                            </div>
                            <div class="col-md-4">
                                <x-form.select name="country" label="Country"
                                               :options="$countries" :value="$opportunity->country ?: $defaultCountry"
                                               :placeholder="null" />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="deadline" label="Application deadline" type="date"
                                              :value="$opportunity->deadline?->format('Y-m-d')"
                                              hint="Leave blank for a rolling intake." />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Reason for this change</h2>
                    </div>
                    <div class="card-body">
                        <x-form.textarea name="reason" label="What changed and why" :rows="3" required
                                         hint="Shown in the review trail for transparency, e.g. &quot;Corrected eligibility criteria&quot; or &quot;Updated award amount&quot;." />
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-lg" type="submit">Save and resubmit for review</button>
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
                        Because the content changed, this listing is unpublished until an administrator reviews it
                        again. It stays visible in your dashboard the whole time. If you only need to push the
                        deadline back, use "Extend deadline" from the dashboard instead - it does not require
                        re-review.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
