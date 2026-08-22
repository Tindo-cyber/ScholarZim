@props([
    'opportunity',
    'score' => null,
    'saved' => false,
    'showSave' => true,
    'showApply' => true,
])

@php
    $days = $opportunity->daysUntilDeadline();
@endphp

<article {{ $attributes->merge(['class' => 'card sz-scholarship-card h-100']) }}>
    <div class="card-body d-flex flex-column gap-3">

        <div class="d-flex gap-3 align-items-start">
            <div class="min-w-0 flex-grow-1">
                <div class="d-flex flex-wrap gap-2 mb-2">
                    @if($opportunity->funding_type)
                        <x-status-badge :label="$opportunity->funding_type" tone="primary" />
                    @endif

                    @if($days !== null && $days >= 0 && $days <= 14)
                        <x-status-badge :label="$days === 0 ? 'Closes today' : 'Closes in ' . $days . ' days'"
                                        tone="danger" icon="clock-history" />
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
                        <a class="btn btn-sm btn-primary" href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Apply</a>
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
                                title="{{ $saved ? 'Remove from saved' : 'Save for later' }}">
                            <x-icon name="bookmark" :size="14" />
                        </button>
                    </form>
                @endif
            @endauth
        </div>
    </div>
</article>
