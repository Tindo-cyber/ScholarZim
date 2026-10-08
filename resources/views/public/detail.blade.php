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

        @if($preview ?? false)
            {{-- Only ever rendered by OpportunityController::preview, from an unsaved form. --}}
            <div class="alert alert-info d-flex gap-2" role="note" id="listing-preview-banner">
                <x-icon name="eye" :size="18" class="flex-shrink-0 mt-1" />
                <div>
                    <strong>Preview.</strong> This is how the listing would look to students. Nothing has been saved,
                    and nobody has been told. Close this tab to go back to the form.
                </div>
            </div>
        @endif

        @if(! empty($conflicts ?? []))
            <div class="alert alert-warning" role="alert" id="listing-conflicts">
                <strong>Check this listing</strong>
                <ul class="mb-0 mt-2">
                    @foreach($conflicts as $conflict)
                        <li>{{ $conflict['message'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if(! empty($reading ?? null))
            {{-- Preview only: what the engine will do with this listing, and how many applicants fit. --}}
            <div class="card mb-3" id="listing-reading">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">How ScholarFit reads your listing</h2>
                </div>
                <div class="card-body">
                    @if(empty($reading['rules']))
                        <p class="small mb-3">
                            Nothing in this listing is checked, so every student with a complete profile will be
                            shown it. If that is not what you want, add the requirements you mean.
                        </p>
                    @else
                        <p class="small text-secondary mb-2">Students are checked against these, and only these:</p>
                        <ul class="small mb-3">
                            @foreach($reading['rules'] as $rule)
                                <li>
                                    {{ $rule['text'] }}
                                    @if($rule['source'] === 'text')
                                        <span class="badge text-bg-warning ms-1">read from {{ $rule['where'] }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                        @if(collect($reading['rules'])->contains('source', 'text'))
                            <p class="small text-secondary">
                                Rules marked <em>read from</em> were picked out of your wording, not set by you. If one is
                                wrong, reword that sentence or fill in the matching setting, which always wins.
                            </p>
                        @endif
                    @endif

                    <p class="mb-1">
                        Applicants who meet every one of these today:
                        <strong id="listing-reading-count">{{ $reading['count'] }}</strong>
                    </p>
                    <p class="small text-secondary mb-0">
                        Counts below {{ $reading['minimum'] }} are never shown as a number, so no individual student
                        can be picked out. You only ever see a figure - never who is in it.
                    </p>
                </div>
            </div>
        @endif

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

        @if(auth()->user()?->isAdmin() && ! $opportunity->isPubliclyVisible())
            {{--
                The moderator's frame, and the only admin-specific thing in
                this view. It can be reached one way: admin.moderation.show,
                which is the single route that renders an unpublished listing -
                the public route answers 404 for anything not publicly visible,
                so an administrator browsing the live site never sees it.
            --}}
            <x-moderation-panel :opportunity="$opportunity" />
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
                        <p class="text-secondary mb-4">Awarded by {{ $opportunity->awardingBodyLine() }}</p>

                        <div class="row g-3 mb-4">
                            @foreach([
                                ['Education level', \App\Support\EducationLevel::label($opportunity->education_level), 'file-text'],
                                ['Field of study', $opportunity->target_field, 'stars'],
                                ['Location', $opportunity->locationLabel(), 'pin'],
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
                                        <span>At least {{ $opportunity->min_academic_points }} ZIMSEC A-Level points (A=5, B=4, C=3, D=2, E=1).</span>
                                    </li>
                                @endif
                                @if($opportunity->max_age)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Aged {{ $opportunity->max_age }} or under.</span>
                                    </li>
                                @endif
                                @if($opportunity->required_province)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Applicants from {{ $opportunity->required_province }} only.</span>
                                    </li>
                                @endif
                                @if($opportunity->target_locality)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Limited to applicants from {{ $opportunity->target_locality }}.</span>
                                    </li>
                                @endif
                                @if($opportunity->minimum_education_level)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Requires at least {{ \App\Support\EducationLevel::label($opportunity->minimum_education_level) }}.</span>
                                    </li>
                                @endif
                                @if($opportunity->requires_results_certificate)
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>Proof of academic results must be on your profile before you apply.</span>
                                    </li>
                                @endif
                                @if($opportunity->subjectRequirements->isNotEmpty())
                                    <li class="d-flex gap-2 align-items-start">
                                        <x-icon name="check" :size="16" class="text-primary mt-1" />
                                        <span>
                                            Required subjects:
                                            @foreach($opportunity->subjectRequirements as $req)
                                                {{ $req->subject?->name ?? 'Subject' }}
                                                @if($req->minimum_grade)
                                                    (minimum {{ $req->minimum_grade }})
                                                @endif
                                                @if(!$loop->last)<span class="text-muted">,</span>@endif
                                            @endforeach
                                            @if($opportunity->subjectRequirements->isNotEmpty())
                                                <span class="d-block text-secondary small mt-1">
                                                    Under {{ $opportunity->subjectRequirements->first()?->qualification?->name ?? 'the stated qualification' }}.
                                                </span>
                                            @endif
                                        </span>
                                    </li>
                                @endif
                            </ul>
                        @else
                            {{--
                                Stated as a fact about the listing, not about the reader.
                                Its absence used to be the only signal, which left "this
                                provider asked for nothing" looking identical to "you
                                passed everything they asked". They are different things
                                and only one of them is a verdict.
                            --}}
                            <h2 class="h6 fw-semibold text-uppercase text-secondary mb-2">Who can apply</h2>
                            <p class="text-secondary mb-4">
                                This scholarship does not specify entry requirements. Read the description
                                below for what the provider is looking for - they decide who is awarded.
                            </p>
                        @endif

                        <h2 class="h6 fw-semibold text-uppercase text-secondary mb-2">About this scholarship</h2>
                        <div class="mb-0">{!! nl2br(e($opportunity->description)) !!}</div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="card mb-4 position-sticky" style="top: 5.5rem;">
                    <div class="card-body">

                        @if($fit)
                            {{--
                                Eligibility, on its own terms: which requirements
                                were met, which were not, or that the listing set
                                none.
                            --}}
                            <x-eligibility-summary :fit="$fit" />
                        @endif

                        <div class="d-grid gap-2">
                            @auth
                                @if(auth()->user()->isApplicant())
                                    @if($acceptedApplication)
                                        {{--
                                            Neither Apply nor Quick apply: the
                                            student already holds this
                                            scholarship, and the server refuses a
                                            second application either way. The
                                            listing itself stays readable - it is
                                            theirs now, so hiding it would be
                                            perverse.
                                        --}}
                                        <div class="alert alert-success mb-0 text-center">
                                            <div class="fw-semibold d-flex align-items-center justify-content-center gap-2">
                                                <x-icon name="stars" :size="18" /> Application accepted
                                            </div>
                                            @if($acceptedApplication->decided_at)
                                                <div class="small mt-1">
                                                    Accepted on {{ $acceptedApplication->decided_at->format('d F Y') }}
                                                </div>
                                            @endif
                                        </div>
                                        <a class="btn btn-outline-success"
                                           href="{{ route('applications.confirmation', $acceptedApplication->application_id) }}">
                                            View your application
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

                        {{--
                            Reporting. Only for a signed-in student looking at the
                            public page (the moderation preview does not pass
                            $hasReported). A student who has already reported is told
                            so rather than offered it again: the unique key would
                            refuse a second report anyway, and a button that only ever
                            fails is worse than no button.
                        --}}
                        @auth
                            @if(auth()->user()->isApplicant() && isset($hasReported))
                                <div class="text-center mt-3">
                                    @if($hasReported)
                                        <span class="small text-secondary">You reported this listing. An administrator will look at it.</span>
                                    @else
                                        {{-- The dialog itself is rendered at the end of the page: inside this sticky
                                             card it sits in a stacking context below Bootstrap's backdrop, which
                                             then covers it and swallows every click. --}}
                                        <button type="button" class="btn btn-link btn-sm text-secondary text-decoration-none"
                                                data-bs-toggle="modal" data-bs-target="#report-{{ $opportunity->opportunity_id }}">
                                            Report this listing
                                        </button>
                                    @endif
                                </div>
                            @endif
                        @endauth                    </div>
                </div>
            </div>
        </div>

        {{--
            Related listings sit below both columns rather than at the foot of
            the left one.

            The action panel is the second column, so on a phone - where the
            columns become rows - everything in the left column came first.
            Six related scholarship cards therefore stood between the
            description and the only button on the page that applies for this
            one. Moving the block out of that column puts the action directly
            after the listing it belongs to, and costs the desktop layout
            nothing: it was always full width down there anyway.
        --}}
        @if($related->isNotEmpty())
            <h2 class="h5 fw-bold mb-3">Similar scholarships</h2>
            <div class="row g-3">
                @foreach($related as $item)
                    <div class="col-md-6 col-lg-4">
                        <x-scholarship-card :opportunity="$item" :show-save="false"
                                            :applied="in_array($item->opportunity_id, $appliedIds, true)"
                                            :accepted="$accepted[$item->opportunity_id] ?? null" />
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@auth
    @if(auth()->user()->isApplicant() && isset($hasReported) && ! $hasReported)
            <x-confirm-dialog :id="'report-' . $opportunity->opportunity_id"
                              :action="route('listing.report', $opportunity->opportunity_id)"
                              :title="'Report: ' . $opportunity->title"
                              confirm-label="Send report"
                              tone="danger"
                              message="Tell us what is wrong. An administrator reads every report. Several reports from different students take a listing down until it has been checked.">
                <x-form.select name="reason" label="What is wrong?" required
                               :options="\App\Support\ReportReason::options()"
                               placeholder="Choose a reason"
                               :bag="'report-' . $opportunity->opportunity_id" />
                <x-form.textarea name="details" :rows="3"
                                 label="Anything else we should know? (optional)"
                                 :bag="'report-' . $opportunity->opportunity_id" />
            </x-confirm-dialog>
    @endif
@endauth

@endsection
