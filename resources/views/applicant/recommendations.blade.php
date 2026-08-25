@extends('layouts.app')

@section('title', 'My matches')

@section('content')

    <x-page-header title="My matches"
                   subtitle="Open scholarships ranked by how well they fit your profile."
                   eyebrow="ScholarFit">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('applicant.profile') }}">Improve my profile</a>
        </x-slot:actions>
    </x-page-header>

    @if($profile->completionPercentage() < 100)
        <div class="alert alert-info">
            Your profile is {{ $profile->completionPercentage() }}% complete. Scores below are calculated from
            what you have filled in so far.
        </div>
    @endif

    <form method="GET" action="{{ route('applicant.recommendations') }}" class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
            <div class="flex-grow-1" style="max-width: 20rem;">
                <label class="form-label" for="min_score">Minimum match score</label>
                <select class="form-select" id="min_score" name="min_score">
                    @foreach([0 => 'Show everything', 45 => 'Moderate fit and above (45%+)', 75 => 'Strong fit only (75%+)'] as $value => $label)
                        <option value="{{ $value }}" @selected($minimumScore === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-primary" type="submit">Filter</button>
        </div>
    </form>

    @if(empty($matches))
        <div class="card">
            <x-empty-state title="No matches at this threshold"
                           message="Lower the minimum score, or add more detail to your profile so more listings qualify."
                           icon="stars"
                           action-label="Edit my profile"
                           :action-href="route('applicant.profile')" />
        </div>
    @else
        <div class="d-grid gap-3">
            @foreach($matches as $match)
                @php $opportunity = $match->opportunity; @endphp

                <div class="card">
                    <div class="card-body">
                        <div class="row g-3 align-items-center">

                            <div class="col-md-2 text-center">
                                <x-match-score :score="$match->matchScore"
                                               :label="$match->breakdown->confidenceLabel" />
                            </div>

                            <div class="col-md-7">
                                <h2 class="h6 fw-bold mb-1">
                                    <a class="text-body text-decoration-none"
                                       href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                                        {{ $opportunity->title }}
                                    </a>
                                </h2>
                                <p class="small text-secondary mb-2">{{ $opportunity->awardingBody() }}</p>

                                <div class="d-flex flex-wrap gap-2 mb-2">
                                    @foreach($match->breakdown->metReasons() as $reason)
                                        <x-status-badge :label="$reason['label']" tone="success" icon="check" />
                                    @endforeach
                                </div>

                                @if($match->breakdown->fixes)
                                    <details class="small">
                                        <summary class="text-secondary">
                                            {{ count($match->breakdown->fixes) }} thing(s) holding this score back
                                        </summary>
                                        {{-- Each one links at the field that fixes it. --}}
                                        <ul class="mt-2 mb-0 ps-3 text-secondary d-grid gap-1">
                                            @foreach($match->breakdown->fixes as $fix)
                                                <li>
                                                    {{ $fix['text'] }}
                                                    @if($fix['target'] === 'profile')
                                                        <a class="fw-semibold"
                                                           href="{{ route('applicant.profile') }}#field-{{ $fix['cta'] }}">Fix this</a>
                                                    @elseif($fix['target'] === 'documents')
                                                        <a class="fw-semibold"
                                                           href="{{ route('applicant.profile') }}#documents">Upload it</a>
                                                    @endif
                                                </li>
                                            @endforeach
                                        </ul>
                                    </details>
                                @endif
                            </div>

                            <div class="col-md-3 d-grid gap-2">
                                @if(in_array($opportunity->opportunity_id, $appliedIds, true))
                                    <x-status-badge label="Applied" tone="success" icon="check-circle" class="justify-content-center" />
                                @else
                                    <a class="btn btn-primary btn-sm"
                                       href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Apply</a>
                                @endif
                                <a class="btn btn-outline-secondary btn-sm"
                                   href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Details</a>

                                <form method="POST"
                                      action="{{ in_array($opportunity->opportunity_id, $savedIds, true)
                                          ? route('applicant.saved.destroy', $opportunity->opportunity_id)
                                          : route('applicant.saved.store', $opportunity->opportunity_id) }}">
                                    @csrf
                                    <button class="btn btn-sm btn-link p-0 text-decoration-none w-100" type="submit">
                                        {{ in_array($opportunity->opportunity_id, $savedIds, true) ? 'Remove from saved' : 'Save for later' }}
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

@endsection
