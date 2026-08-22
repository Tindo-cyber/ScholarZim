@props([
    'action',
    'filters' => [],
    'providerNames' => [],
    'targetFields' => [],
])

@php
    $activeCount = count(array_filter($filters, static fn ($v) => filled($v)));
@endphp

<form method="GET" action="{{ $action }}" class="card mb-4">
    <div class="card-body">
        <div class="row g-3 align-items-end">

            <div class="col-12 col-lg-4">
                <label class="form-label" for="filter-keyword">Search</label>
                <input type="search" class="form-control" id="filter-keyword" name="keyword"
                       value="{{ $filters['keyword'] ?? '' }}" placeholder="Title, provider, or keyword">
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-level">Level</label>
                <select class="form-select" id="filter-level" name="education_level">
                    <option value="">Any</option>
                    @foreach(\App\Support\FormOptions::educationLevelGroups() as $group => $levels)
                        <optgroup label="{{ $group }}">
                            @foreach($levels as $level)
                                <option value="{{ $level }}" @selected(($filters['education_level'] ?? '') === $level)>{{ $level }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-field">Field</label>
                <select class="form-select" id="filter-field" name="field_of_study">
                    <option value="">Any</option>
                    @foreach(($targetFields ?: \App\Support\FormOptions::FIELDS_OF_STUDY) as $field)
                        <option value="{{ $field }}" @selected(($filters['field_of_study'] ?? '') === $field)>{{ $field }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-country">Country</label>
                <select class="form-select" id="filter-country" name="country">
                    <option value="">Any</option>
                    @foreach(\App\Support\FormOptions::COUNTRIES as $country)
                        <option value="{{ $country }}" @selected(($filters['country'] ?? '') === $country)>{{ $country }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-funding">Funding</label>
                <select class="form-select" id="filter-funding" name="funding_type">
                    <option value="">Any</option>
                    @foreach(\App\Support\FormOptions::FUNDING_TYPES as $funding)
                        <option value="{{ $funding }}" @selected(($filters['funding_type'] ?? '') === $funding)>{{ $funding }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-12 col-lg-4">
                <label class="form-label" for="filter-provider">Awarding body</label>
                <select class="form-select" id="filter-provider" name="provider">
                    <option value="">Any</option>
                    @foreach($providerNames as $provider)
                        <option value="{{ $provider }}" @selected(($filters['provider'] ?? '') === $provider)>{{ $provider }}</option>
                    @endforeach
                </select>
            </div>

            <div class="col-6 col-lg-3">
                <label class="form-label" for="filter-deadline">Deadline before</label>
                <input type="date" class="form-control" id="filter-deadline" name="deadline_before"
                       value="{{ $filters['deadline_before'] ?? '' }}">
            </div>

            <div class="col-6 col-lg-5 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">
                    Apply filters
                    @if($activeCount > 0)
                        <span class="badge bg-white text-primary ms-1">{{ $activeCount }}</span>
                    @endif
                </button>
                @if($activeCount > 0)
                    <a class="btn btn-outline-secondary" href="{{ $action }}">Clear</a>
                @endif
            </div>
        </div>
    </div>
</form>
