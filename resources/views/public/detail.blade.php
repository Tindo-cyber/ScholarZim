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
                                                    :applied="in_array($item->opportunity_id, $appliedIds, true)" />
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="col-lg-4">
                <div class="card mb-4 position-sticky" style="top: 5.5rem;">
                    <div class="card-body">

                        @if($fit)
                            <div class="text-center mb-3">
                                <x-match-score :score="$fit->matchScore"
                                               :label="$fit->breakdown->confidenceLabel"
                                               size="lg" />
                            </div>

                            <p class="small text-secondary text-center">{{ $fit->breakdown->explanation }}</p>

                            <h3 class="h6 fw-semibold mt-4 mb-2">Why this score</h3>
                            <ul class="list-unstyled d-grid gap-2 mb-3">
                                @foreach($fit->breakdown->reasons as $reason)
                                    <li class="sz-fit-reason small">
                                        <x-icon :name="$reason['met'] ? 'check-circle' : 'x-circle'"
                                                :size="16"
                                                class="text-{{ $reason['met'] ? 'success' : 'secondary' }} mt-1" />
                                        <span class="{{ $reason['met'] ? '' : 'text-secondary' }}">{{ $reason['label'] }}</span>
                                    </li>
                                @endforeach
                            </ul>

                            @if($fit->breakdown->missingRequirements)
                                <div class="alert alert-warning small mb-3">
                                    <div class="fw-semibold mb-1">To improve your score</div>
                                    <ul class="mb-0 ps-3">
                                        @foreach($fit->breakdown->missingRequirements as $item)
                                            <li>{{ $item }}</li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        @endif

                        <div class="d-grid gap-2">
                            @auth
                                @if(auth()->user()->isApplicant())
                                    @if($hasApplied)
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
