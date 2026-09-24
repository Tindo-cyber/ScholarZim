@extends('layouts.app')

@section('title', 'My matches')

@section('content')

    <x-page-header title="My matches"
                   subtitle="Open scholarships ranked by how well they fit your profile."
                   eyebrow="ScholarFit">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('applicant.profile') }}">Improve my profile</a>
            <a class="btn btn-primary" href="{{ route('opportunities.index') }}">Find scholarships</a>
        </x-slot:actions>
    </x-page-header>

    {{-- Shown only while it is actionable. At 100% it is congratulation, which
         is not information, and it would sit above the matches on every visit. --}}
    @if($profile->completionPercentage() < 100)
        <x-profile-progress :profile="$profile" class="mb-4" />
    @endif

    <form method="GET" action="{{ route('applicant.recommendations') }}" class="card mb-4">
        <div class="card-body d-flex flex-wrap gap-3 align-items-end">
            <div class="flex-grow-1 sz-filter-field">
                <label class="form-label" for="min_score">Minimum match score</label>
                <select class="form-select" id="min_score" name="min_score">
                    @foreach([0 => 'Show everything', 45 => 'Moderate fit and above (45%+)', 75 => 'Strong fit only (75%+)'] as $value => $label)
                        <option value="{{ $value }}" @selected($minimumScore === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <button class="btn btn-outline-primary" type="submit">Apply filter</button>
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
        <h2 class="h6 fw-bold mb-3">Matches for you</h2>
        <p class="text-secondary small" aria-live="polite">
            {{ count($matches) }} {{ \Illuminate\Support\Str::plural('scholarship', count($matches)) }} matched your profile.
        </p>

        <div class="d-grid gap-3">
            @foreach($matches as $match)
                @php
                    $opportunity = $match->opportunity;
                    $acceptedApplication = $accepted[$opportunity->opportunity_id] ?? null;
                    $hasApplied = in_array($opportunity->opportunity_id, $appliedIds, true);
                    $isSaved = in_array($opportunity->opportunity_id, $savedIds, true);
                @endphp

                {{--
                    The order of this card is the argument it makes.

                    What the scholarship is, then whether the student can apply,
                    then why it fits, and only then the number. A score dial
                    sharing the title's own row put a percentage in front of a
                    reader before they had read "Eligible" - easy to misread as
                    a comment on eligibility itself, e.g. "60% / Moderate
                    confidence" beside a listing the applicant fully qualifies
                    for. Eligibility is therefore established first and alone;
                    the score, a hint about fit rather than a verdict, follows
                    once it has something to be read against.
                --}}
                <article class="card sz-match-card">
                    <div class="card-body">
                        <div class="mb-2">
                            <h2 class="h6 fw-bold mb-1">
                                <a class="text-body text-decoration-none stretched-link"
                                   href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                                    {{ $opportunity->title }}
                                </a>
                            </h2>
                            <p class="small text-secondary mb-0">{{ $opportunity->awardingBody() }}</p>
                        </div>

                        <x-eligibility-summary :fit="$match" variant="compact" class="mb-3" />

                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-3">
                            <ul class="list-unstyled d-flex flex-wrap gap-3 small text-secondary mb-0">
                                <li class="d-flex align-items-center gap-1">
                                    <x-icon name="calendar" :size="14" />
                                    {{ $opportunity->deadline?->format('d M Y') ?? 'No deadline' }}
                                </li>
                                @if($opportunity->education_level)
                                    <li class="d-flex align-items-center gap-1">
                                        <x-icon name="file-text" :size="14" />
                                        {{ \App\Support\EducationLevel::label($opportunity->education_level) }}
                                    </li>
                                @endif
                                @if($opportunity->target_field)
                                    <li class="d-flex align-items-center gap-1">
                                        <x-icon name="stars" :size="14" />{{ $opportunity->target_field }}
                                    </li>
                                @endif
                                @if($opportunity->formattedAward())
                                    <li class="d-flex align-items-center gap-1">
                                        <x-icon name="coins" :size="14" />{{ $opportunity->formattedAward() }}
                                    </li>
                                @endif
                            </ul>

                            {{-- Secondary to eligibility, and kept to the smaller size:
                                 a match score is a hint about fit, not a verdict. --}}
                            <div class="text-center flex-shrink-0 position-relative z-1">
                                <x-match-score :score="$match->matchScore"
                                               :label="$match->breakdown->confidenceLabel" />
                            </div>
                        </div>

                        <div class="d-grid gap-2 mb-3">
                            <x-score-breakdown :fit="$match" />
                            <x-score-fixes :fit="$match" />
                        </div>

                        <div class="d-flex flex-wrap gap-2 position-relative z-1">
                            @if($acceptedApplication)
                                <a class="text-decoration-none"
                                   href="{{ route('applications.confirmation', $acceptedApplication->application_id) }}">
                                    <x-status-badge label="Accepted" tone="success" />
                                </a>
                            @elseif($hasApplied)
                                <x-status-badge label="Applied" tone="success" />
                            @else
                                <a class="btn btn-primary btn-sm"
                                   href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Apply</a>
                            @endif

                            <a class="btn btn-outline-secondary btn-sm"
                               href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">View details</a>

                            <form method="POST" class="m-0 ms-auto"
                                  action="{{ $isSaved
                                      ? route('applicant.saved.destroy', $opportunity->opportunity_id)
                                      : route('applicant.saved.store', $opportunity->opportunity_id) }}">
                                @csrf
                                <button class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-1"
                                        type="submit"
                                        aria-label="{{ $isSaved ? 'Remove ' . $opportunity->title . ' from saved' : 'Save ' . $opportunity->title . ' for later' }}">
                                    <x-icon name="bookmark" :size="14" />
                                    {{ $isSaved ? 'Saved' : 'Save' }}
                                </button>
                            </form>
                        </div>
                    </div>
                </article>
            @endforeach
        </div>
    @endif

    @if(! empty($notEligible))
        <h2 class="h6 fw-bold mt-5 mb-3">Scholarships you don't qualify for yet</h2>
        <p class="text-secondary small mb-3">
            These state requirements your profile doesn't currently meet.
        </p>

        <div class="d-grid gap-3">
            @foreach($notEligible as $match)
                @php $opportunity = $match->opportunity; @endphp
                <article class="card sz-match-card">
                    <div class="card-body">
                        <div class="mb-2">
                            <h3 class="h6 fw-bold mb-1">
                                <a class="text-body text-decoration-none stretched-link"
                                   href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                                    {{ $opportunity->title }}
                                </a>
                            </h3>
                            <p class="small text-secondary mb-0">{{ $opportunity->awardingBody() }}</p>
                        </div>

                        <x-eligibility-summary :fit="$match" variant="full" class="mb-0" />
                    </div>
                </article>
            @endforeach
        </div>
    @endif

@endsection
