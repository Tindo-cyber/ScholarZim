@props([
    'action',
    'filters' => [],
    'providerNames' => [],
    'targetFields' => [],
    'resultCount' => null,
])

@php
    use App\Support\FormOptions;

    // 'sort' is an ordering, not a filter: it must not count towards the active
    // filter badge, and clearing filters must not silently reset it either.
    $facets = collect($filters)->except('sort')->filter(fn ($v) => filled($v));
    $activeCount = $facets->count();
    $activeSort = $filters['sort'] ?? FormOptions::DEFAULT_SORT;

    $chipLabels = [
        'keyword' => 'Search',
        'education_level' => 'Level',
        'field_of_study' => 'Field',
        'country' => 'Country',
        'provider' => 'Awarding body',
        'funding_type' => 'Funding',
        'deadline_before' => 'Closes before',
        'min_award' => 'Min award',
        'renewable_only' => 'Renewable only',
    ];
@endphp

<div class="mb-4">
    <form method="GET" action="{{ $action }}" class="card">
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
                        @foreach(FormOptions::educationLevelGroups() as $group => $levels)
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
                        @foreach(($targetFields ?: FormOptions::FIELDS_OF_STUDY) as $field)
                            <option value="{{ $field }}" @selected(($filters['field_of_study'] ?? '') === $field)>{{ $field }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="filter-country">Country</label>
                    <select class="form-select" id="filter-country" name="country">
                        <option value="">Any</option>
                        @foreach(FormOptions::COUNTRIES as $country)
                            <option value="{{ $country }}" @selected(($filters['country'] ?? '') === $country)>{{ $country }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="filter-funding">Funding</label>
                    <select class="form-select" id="filter-funding" name="funding_type">
                        <option value="">Any</option>
                        @foreach(FormOptions::FUNDING_TYPES as $funding)
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

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="filter-deadline">Closes before</label>
                    <input type="date" class="form-control" id="filter-deadline" name="deadline_before"
                           value="{{ $filters['deadline_before'] ?? '' }}">
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="filter-min-award">
                        Min award ({{ FormOptions::DEFAULT_CURRENCY }})
                    </label>
                    <input type="number" min="0" step="100" class="form-control" id="filter-min-award"
                           name="min_award" value="{{ $filters['min_award'] ?? '' }}" placeholder="Any">
                    <div class="form-text">Hides listings with no stated value.</div>
                </div>

                <div class="col-6 col-lg-2">
                    <label class="form-label" for="filter-sort">Sort by</label>
                    <select class="form-select" id="filter-sort" name="sort">
                        @foreach(FormOptions::SORT_OPTIONS as $key => $label)
                            <option value="{{ $key }}" @selected($activeSort === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-6 col-lg-2 d-flex gap-2">
                    <button class="btn btn-primary flex-grow-1" type="submit">
                        Apply
                        @if($activeCount > 0)
                            <span class="badge bg-white text-primary ms-1">{{ $activeCount }}</span>
                        @endif
                    </button>
                    @if($activeCount > 0)
                        <a class="btn btn-outline-secondary" href="{{ $action }}?sort={{ $activeSort }}">Clear</a>
                    @endif
                </div>
            </div>
        </div>
    </form>

    @if($activeCount > 0 || !is_null($resultCount))
        <div class="d-flex flex-wrap align-items-center gap-2 mt-3">
            @if(!is_null($resultCount))
                <span class="small text-secondary me-1" aria-live="polite">
                    {{ number_format($resultCount) }} {{ Str::plural('result', $resultCount) }}
                </span>
            @endif

            {{--
                Each chip removes exactly its own filter and keeps the rest, which
                is the difference between narrowing a search and starting over.
            --}}
            @foreach($facets as $key => $value)
                <a class="badge rounded-pill bg-primary-subtle text-primary text-decoration-none d-inline-flex align-items-center gap-1"
                   href="{{ $action }}?{{ http_build_query(collect($filters)->filter(fn ($v) => filled($v))->except($key)->all()) }}"
                   aria-label="Remove the {{ $chipLabels[$key] ?? $key }} filter">
                    <span>{{ $chipLabels[$key] ?? $key }}: {{ $key === 'renewable_only' ? 'Yes' : $value }}</span>
                    <x-icon name="x-circle" :size="12" />
                </a>
            @endforeach

        </div>
    @endif

</div>
