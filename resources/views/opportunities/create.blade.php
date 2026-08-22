@extends('layouts.app')

@section('title', 'Post a scholarship')

@section('content')

    <x-page-header title="Post a scholarship"
                   subtitle="Listings go live once an administrator has reviewed them."
                   eyebrow="Provider" />

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('opportunities.store') }}" novalidate>
                @csrf

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">The offer</h2>
                    </div>
                    <div class="card-body">
                        <x-form.input name="title" label="Scholarship title" required
                                      hint="For example: Zimplats Engineering Undergraduate Bursary 2026." />

                        <x-form.input name="provider_display_name" label="Awarding body"
                                      :value="auth()->user()->full_name"
                                      list="awarding-body-list"
                                      hint="Shown publicly. Defaults to your organisation name." />
                        <datalist id="awarding-body-list">
                            @foreach($awardingBodySuggestions as $suggestion)
                                <option value="{{ $suggestion }}"></option>
                            @endforeach
                        </datalist>

                        <x-form.textarea name="description" label="Full description" :rows="8" required
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
                                               placeholder="Any level" />
                            </div>
                            <div class="col-md-6">
                                <x-form.input name="target_field" label="Field of study"
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
                                               :options="$fundingTypes" placeholder="Not specified" />
                            </div>
                            <div class="col-md-4">
                                <x-form.select name="country" label="Country"
                                               :options="$countries" :value="$defaultCountry"
                                               :placeholder="null" />
                            </div>
                            <div class="col-md-4">
                                <x-form.input name="deadline" label="Application deadline" type="date"
                                              hint="Leave blank for a rolling intake." />
                            </div>
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-lg" type="submit">Submit for review</button>
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
                    <ol class="list-unstyled d-grid gap-3 mb-0 small">
                        <li class="d-flex gap-3">
                            <span class="sz-step-number flex-shrink-0" style="width:2rem;height:2rem;">1</span>
                            <span>Your listing is queued for administrator review. It is not public yet.</span>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="sz-step-number flex-shrink-0" style="width:2rem;height:2rem;">2</span>
                            <span>Once approved, it appears in search and students matching it are notified.</span>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="sz-step-number flex-shrink-0" style="width:2rem;height:2rem;">3</span>
                            <span>Applications land in your inbox, where you can review and decide on each one.</span>
                        </li>
                    </ol>

                    <hr>

                    <p class="small text-secondary mb-0">
                        The fields you fill in here feed ScholarFit directly: education level, field of study,
                        country, and deadline are what students are scored against.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
