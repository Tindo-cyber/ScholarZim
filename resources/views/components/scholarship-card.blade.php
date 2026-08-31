@props([
    'opportunity',
    'score' => null,
    'saved' => false,
    'applied' => false,
    // The viewer's accepted application for this listing, if they have one.
    // Defaults to null so a page that has no reason to load them renders
    // unchanged rather than erroring on a missing prop.
    'accepted' => null,
    'showSave' => true,
    'showApply' => true,
])

@php
    $deadlineTone = $opportunity->deadlineTone();
    $deadlineLabel = $opportunity->deadlineLabel();
    $award = $opportunity->formattedAward();
@endphp

<article {{ $attributes->merge(['class' => 'card sz-scholarship-card h-100']) }}>
    <div class="card-body d-flex flex-column gap-3">

        <div class="d-flex gap-3 align-items-start">
            <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    @if($opportunity->funding_type)
                        <x-status-badge :label="$opportunity->funding_type" tone="primary" />
                    @endif

                    @if($opportunity->is_renewable)
                        <x-status-badge label="Renewable" tone="info" />
                    @endif

                    {{--
                        The chip only appears once the deadline is close enough to
                        change what a student does about it; a listing closing in
                        three months does not need a countdown.
                    --}}
                    @if($deadlineTone && $deadlineLabel)
                        <x-status-badge :label="$deadlineLabel" :tone="$deadlineTone" icon="clock-history" />
                    @endif
                </div>

                <h3 class="h6 fw-bold mb-1">
                    <a class="stretched-link text-body text-decoration-none"
                       href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">
                        {{ $opportunity->title }}
                    </a>
                </h3>

                <p class="small text-secondary mb-0">{{ $opportunity->awardingBody() }}</p>
            </div>

            @if($score !== null)
                <x-match-score :score="$score" :show-label="false" class="position-relative z-1" />
            @endif
        </div>

        @if($award)
            <div class="d-flex align-items-center gap-2">
                <x-icon name="coins" :size="18" class="text-success" />
                <span class="fw-bold">{{ $award }}</span>
                @if($opportunity->award_slots)
                    <span class="small text-secondary">
                        · {{ $opportunity->award_slots }} {{ Str::plural('award', $opportunity->award_slots) }}
                    </span>
                @endif
            </div>
        @endif

        @if($opportunity->description)
            <p class="small text-secondary mb-0 sz-clamp-2">{{ $opportunity->description }}</p>
        @endif

        <ul class="list-unstyled d-flex flex-wrap gap-3 small text-secondary mb-0">
            @if($opportunity->education_level)
                <li class="d-flex align-items-center gap-1"><x-icon name="file-text" :size="14" />{{ $opportunity->education_level }}</li>
            @endif
            @if($opportunity->target_field)
                <li class="d-flex align-items-center gap-1"><x-icon name="stars" :size="14" />{{ $opportunity->target_field }}</li>
            @endif
            @if($opportunity->country)
                <li class="d-flex align-items-center gap-1"><x-icon name="pin" :size="14" />{{ $opportunity->country }}</li>
            @endif
            <li class="d-flex align-items-center gap-1">
                <x-icon name="calendar" :size="14" />
                {{ $opportunity->deadline?->format('d M Y') ?? 'No deadline' }}
            </li>
        </ul>

        <div class="d-flex flex-wrap gap-2 mt-auto pt-2 position-relative z-1">
            @if($showApply)
                @auth
                    @if(auth()->user()->isApplicant())
                        @if($accepted)
                            {{-- Won, not merely applied for. It links at the decision. --}}
                            <a class="text-decoration-none"
                               href="{{ route('applications.confirmation', $accepted->application_id) }}">
                                <x-status-badge label="Accepted" tone="success" icon="stars" />
                            </a>
                        @elseif($applied)
                            <x-status-badge label="Applied" tone="success" icon="check-circle" />
                        @else
                            <a class="btn btn-sm btn-primary" href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Apply</a>
                        @endif
                    @endif
                @else
                    <a class="btn btn-sm btn-primary" href="{{ route('login') }}">Sign in to apply</a>
                @endauth
            @endif

            <a class="btn btn-sm btn-outline-secondary"
               href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Details</a>

            @auth
                @if($showSave && auth()->user()->isApplicant())
                    <form method="POST"
                          action="{{ $saved
                              ? route('applicant.saved.destroy', $opportunity->opportunity_id)
                              : route('applicant.saved.store', $opportunity->opportunity_id) }}"
                          class="m-0 ms-auto">
                        @csrf
                        <button class="btn btn-sm {{ $saved ? 'btn-primary' : 'btn-outline-secondary' }}"
                                type="submit"
                                title="{{ $saved ? 'Remove from saved' : 'Save for later' }}"
                                aria-label="{{ $saved ? 'Remove ' . $opportunity->title . ' from saved' : 'Save ' . $opportunity->title . ' for later' }}">
                            <x-icon name="bookmark" :size="14" />
                        </button>
                    </form>
                @endif
            @endauth
        </div>
    </div>
</article>
