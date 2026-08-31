{{-- Signed-in users keep their app shell (sidebar/topbar) here; guests get the public marketing shell. --}}
@extends(auth()->check() ? 'layouts.app' : 'layouts.public')

@section('title', $opportunity->title)
@section('meta_description', Str::limit(strip_tags($opportunity->description ?? ''), 150))

@section('content')
    <div class="{{ auth()->check() ? '' : 'container py-4 py-lg-5' }}">

        <nav aria-label="breadcrumb" class="mb-3">
            <ol class="breadcrumb">
                <li class="breadcrumb-item">
                    <a href="{{ auth()->check() ? route('dashboard') : route('home') }}">
                        {{ auth()->check() ? 'Dashboard' : 'Home' }}
                    </a>
                </li>
                <li class="breadcrumb-item">
                    <a href="{{ auth()->check() ? route('opportunities.index') : route('scholarships.index') }}">Scholarships</a>
                </li>
                <li class="breadcrumb-item active" aria-current="page">{{ Str::limit($opportunity->title, 40) }}</li>
            </ol>
        </nav>

        @if(($duplicates ?? collect())->isNotEmpty())
            {{--
                Only ever rendered from the admin moderation preview, which is the
                one place this variable is passed.
            --}}
            <div class="alert alert-warning" role="alert">
                <div class="d-flex gap-2 align-items-start">
                    <x-icon name="shield" :size="20" class="flex-shrink-0 mt-1" />
                    <div>
                        <div class="fw-semibold mb-1">This may be a duplicate</div>
                        <p class="small mb-2">
                            {{ $duplicates->count() }} existing {{ Str::plural('listing', $duplicates->count()) }}
                            share this title or this awarding body and closing date. Two intakes of the same
                            annual award are legitimate - check before publishing.
                        </p>
                        <ul class="small mb-0 ps-3">
                            @foreach($duplicates as $duplicate)
                                <li>
                                    <a href="{{ route('admin.moderation.show', $duplicate->opportunity_id) }}">
                                        {{ $duplicate->title }}
                                    </a>
                                    - {{ $duplicate->moderationLabel() }},
                                    closes {{ $duplicate->deadline?->format('d M Y') ?? 'no deadline' }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        <div class="row g-4">
            <div class="col-lg-8">
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <x-status-badge :label="$opportunity->statusLabel()"
                                            :tone="\App\Support\OpportunityStatus::badgeTone($opportunity->status)" />
                            @if($opportunity->funding_type)
                                <x-status-badge :label="$opportunity->funding_type" tone="primary" />
                            @endif
                            @if($opportunity->isClosingSoon())
                                <x-status-badge label="Closing soon" tone="danger" icon="clock-history" />
                            @endif
                        </div>

                        <h1 class="h3 fw-bold mb-2">{{ $opportunity->title }}</h1>
                        <p class="text-secondary mb-4">Awarded by {{ $opportunity->awardingBody() }}</p>

                        <div class="row g-3 mb-4">
                            @foreach([
                                ['Education level', $opportunity->education_level, 'file-text'],
                                ['Field of study', $opportunity->target_field, 'stars'],
                                ['Country', $opportunity->country, 'pin'],
                                ['Deadline', $opportunity->deadline?->format('d M Y') ?? 'No deadline', 'calendar'],
                            ] as [$label, $value, $icon])
                                <div class="col-6 col-md-3">
                                    <div class="border rounded-3 p-3 h-100">
                                        <div class="text-secondary small d-flex align-items-center gap-1 mb-1">
                                            <x-icon :name="$icon" :size="14" />{{ $label }}
                                        </div>
                                        <div class="fw-semibold">{{ $value ?: 'Any' }}</div>
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        @if($opportunity->hasAwardValue() || $opportunity->award_slots || $opportunity->is_renewable)
                            <div class="border rounded-3 p-3 p-lg-4 mb-4 sz-award-panel">
                                <div class="d-flex flex-wrap align-items-center gap-3">
                                    <span class="sz-stat-icon bg-success-subtle text-success rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0">
                                        <x-icon name="coins" :size="22" />
                                    </span>
                                    <div class="min-w-0">
                                        <div class="text-secondary small text-uppercase fw-semibold">What you get</div>
                                        <div class="fs-4 fw-bold lh-1 my-1">
                                            {{ $opportunity->formattedAward() ?? $opportunity->funding_type ?? 'Value not stated' }}
                                        </div>
                                        <div class="small text-secondary">
                                            {{ $opportunity->awardSummary() }}
                                            @if($opportunity->award_slots)
                                                · {{ $opportunity->award_slots }}
                                                {{ Str::plural('award', $opportunity->award_slots) }} available
                                            @endif
                                        </div>
                                    </div>

                                    @if($opportunity->external_url)
                                        <a class="btn btn-outline-secondary ms-lg-auto"
                                           href="{{ $opportunity->external_url }}"
                                           target="_blank" rel="noopener noreferrer nofollow">
                                            <x-icon name="external" :size="16" />
                                            Provider's own page
                                        </a>
                                    @endif
                                </div>
                            </div>
                        @endif

                        @if($opportunity->hasEligibilityRules())
                            <h2 class="h6 fw-semibold text-uppercase text-secondary mb-2">Who can apply</h2>
                            <ul class="list-unstyled d-grid gap-2 mb-4">
                                @if($opportunity->min_academic_points)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>At least {{ $opportunity->min_academic_points }} A-Level points.</span>
                                    </li>
                                @endif
                                @if($opportunity->max_age)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Aged {{ $opportunity->max_age }} or under.</span>
                                    </li>
                                @endif
                                @if($opportunity->required_citizenship)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>{{ $opportunity->required_citizenship }} citizens only.</span>
                                    </li>
                                @endif
                                @if($opportunity->required_province)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Applicants from {{ $opportunity->required_province }} only.</span>
                                    </li>
                                @endif
                                @if($opportunity->requires_results_certificate)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>A results certificate must be on your profile before you apply.</span>
                                    </li>
                                @endif
                            </ul>
                        @endif

                        <h2 class="h6 fw-semibold text-uppercase text-secondary mb-2">About this scholarship</h2>
                        <div class="mb-0">{!! nl2br(e($opportunity->description)) !!}</div>
                    </div>
                </div>

                @if($related->isNotEmpty())
                    <h2 class="h5 fw-bold mb-3">Similar scholarships</h2>
                    <div class="row g-3">
                        @foreach($related as $item)
                            <div class="col-md-4">
                                <x-scholarship-card :opportunity="$item" :show-save="false"
                                                    :applied="in_array($item->opportunity_id, $appliedIds, true)"
                                                    :award="$awards[$item->opportunity_id] ?? null" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="col-lg-4">
                <div class="card mb-4 position-sticky" style="top: 5.5rem;">
                    <div class="card-body">

                        @if($fit)
                            @if(! $fit->isEligible())
                                {{--
                                    A hard rule failed, so no percentage is shown at
                                    all. A number next to "not eligible" only invites
                                    the reader to argue with it.
                                --}}
                                <div class="alert alert-danger" role="alert">
                                    <div class="d-flex gap-2 align-items-start">
                                        <x-icon name="x-circle" :size="20" class="flex-shrink-0 mt-1" />
                                        <div>
                                            <div class="fw-semibold mb-1">You are not eligible for this award</div>
                                            <ul class="mb-0 ps-3 small">
                                                @foreach($fit->breakdown->disqualifiers as $blocker)
                                                    <li>
                                                        {{ $blocker['text'] }}
                                                        @if($blocker['target'] === 'profile')
                                                            <a href="{{ route('applicant.profile') }}#field-{{ $blocker['cta'] }}">Update your profile</a>
                                                        @elseif($blocker['target'] === 'documents')
                                                            <a href="{{ route('applicant.profile') }}#documents">Upload it</a>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    </div>
                                </div>
                            @else
                                <div class="text-center mb-3">
                                    <x-match-score :score="$fit->matchScore"
                                                   :label="$fit->breakdown->confidenceLabel"
                                                   size="lg" />
                                </div>

                                <p class="small text-secondary text-center">{{ $fit->breakdown->explanation }}</p>

                                {{--
                                    One line per dimension, read straight off the
                                    same DimensionResult objects the score was
                                    summed from - so what a student is told here
                                    cannot drift away from what they were scored.
                                --}}
                                <h3 class="h6 fw-semibold mt-4 mb-2">Why this score</h3>
                                <ul class="list-unstyled d-grid gap-2 mb-3">
                                    @foreach($fit->breakdown->dimensionResults as $dimension)
                                        <li class="sz-fit-reason small">
                                            <x-icon :name="$dimension->ratio >= 0.5 ? 'check-circle' : 'x-circle'"
                                                    :size="16"
                                                    class="text-{{ $dimension->ratio >= 0.5 ? 'success' : 'secondary' }} mt-1" />
                                            <span class="{{ $dimension->ratio >= 0.5 ? '' : 'text-secondary' }}">
                                                <strong>{{ $dimension->scoreLine() }}</strong>
                                                <span class="d-block text-secondary">{{ $dimension->detail }}</span>
                                            </span>
                                        </li>
                                    @endforeach
                                </ul>

                                @if($fit->breakdown->fixes)
                                    {{--
                                        Each shortfall links at the field that fixes
                                        it: telling a student what is missing without
                                        saying where to put it is only half an answer.
                                    --}}
                                    <div class="alert alert-warning small mb-3">
                                        <div class="fw-semibold mb-1">To improve your score</div>
                                        <ul class="mb-0 ps-3 d-grid gap-1">
                                            @foreach($fit->breakdown->fixes as $fix)
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
                                    </div>
                                @endif
                            @endif
                        @endif

                        <div class="d-grid gap-2">
                            @auth
                                @if(auth()->user()->isApplicant())
                                    @if($award)
                                        {{--
                                            Neither Apply nor Quick apply: the
                                            student already holds this award, and
                                            the server refuses a second
                                            application either way. The listing
                                            itself stays readable - it is theirs
                                            now, so hiding it would be perverse.
                                        --}}
                                        <div class="alert alert-success mb-0 text-center">
                                            <div class="fw-semibold d-flex align-items-center justify-content-center gap-2">
                                                <x-icon name="stars" :size="18" /> Scholarship awarded
                                            </div>
                                            @if($award->awarded_at)
                                                <div class="small mt-1">Awarded on {{ $award->awarded_at->format('d F Y') }}</div>
                                            @endif
                                        </div>
                                        <a class="btn btn-outline-success"
                                           href="{{ route('applications.confirmation', $award->application_id) }}">
                                            View your award
                                        </a>
                                    @elseif($hasApplied)
                                        <button class="btn btn-success btn-lg" type="button" disabled>
                                            <x-icon name="check-circle" :size="16" /> Applied
                                        </button>
                                    @else
                                        <a class="btn btn-primary btn-lg"
                                           href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Apply now</a>
                                    @endif

                                    <form method="POST"
                                          action="{{ $isSaved
                                              ? route('applicant.saved.destroy', $opportunity->opportunity_id)
                                              : route('applicant.saved.store', $opportunity->opportunity_id) }}">
                                        @csrf
                                        <button class="btn btn-outline-secondary w-100" type="submit">
                                            {{ $isSaved ? 'Remove from saved' : 'Save for later' }}
                                        </button>
                                    </form>
                                @else
                                    <a class="btn btn-outline-secondary" href="{{ route('dashboard') }}">Go to dashboard</a>
                                @endif
                            @else
                                <a class="btn btn-primary btn-lg" href="{{ route('login') }}">Sign in to apply</a>
                                <a class="btn btn-outline-secondary" href="{{ route('register') }}">Create a free account</a>
                            @endauth
                        </div>

                        @if($opportunity->deadline)
                            <p class="small text-secondary text-center mt-3 mb-0">
                                Applications close {{ $opportunity->deadline->format('d M Y') }}
                                ({{ $opportunity->deadline->diffForHumans() }}).
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
