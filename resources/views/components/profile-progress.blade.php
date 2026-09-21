@props([
    'profile',
    /**
     * 'bar'       one line, a rail and the next thing to do - for a page that
     *             is about something else and only needs to nudge.
     * 'checklist' every item with its hint - for the profile itself, where the
     *             reader came to work through them.
     */
    'variant' => 'bar',
    'heading' => 'Profile completeness',
])

@php
    /**
     * How complete the profile is, and what would complete it.
     *
     * Three pages showed this three ways: a dial and a bare list of gaps on the
     * dashboard, a dial and an annotated checklist on the profile, and a
     * sentence in an alert on the matches page. Same question, same data, three
     * answers - so the percentage a student saw depended on where they were
     * standing.
     *
     * Everything comes from ApplicantProfile::completionChecklist(), which is
     * also what the reminder job reads. The nudge email and this panel cannot
     * disagree about what is missing, because there is one list.
     */
    $percent = $profile->completionPercentage();
    $checklist = $profile->completionChecklist();
    $outstanding = array_values(array_filter($checklist, static fn (array $item) => ! $item['done']));
    $complete = $outstanding === [];

    // Anchors differ by field: documents and the guardian card are their own
    // sections, everything else is a form field.
    $anchor = static fn (string $key) => match ($key) {
        'documents' => 'documents',
        'guardian' => 'sz-guardian-card',
        default => 'field-' . $key,
    };

    $tone = match (true) {
        $percent >= 100 => 'success',
        $percent >= 60 => 'primary',
        default => 'warning',
    };
@endphp

@if($variant === 'checklist')
    <div {{ $attributes->merge(['class' => 'card']) }}>
        <div class="card-header">
            <h2 class="h6 fw-semibold mb-0">{{ $heading }}</h2>
        </div>
        <div class="card-body">
            <div class="d-flex align-items-baseline gap-2 mb-1">
                <span class="fw-bold fs-5 sz-tabular">{{ $percent }}%</span>
                <span class="small text-secondary">
                    {{ $complete ? 'complete' : count($outstanding) . ' ' . \Illuminate\Support\Str::plural('item', count($outstanding)) . ' left' }}
                </span>
            </div>

            <div class="progress sz-progress-thin mb-3" role="progressbar"
                 aria-label="{{ $heading }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                <div class="progress-bar bg-{{ $tone }}" style="width: {{ $percent }}%"></div>
            </div>

            <p class="small text-secondary">
                @if($complete)
                    Every field ScholarFit reads is filled in. Your matches are as accurate as we can make them.
                @else
                    Each item below is a field ScholarFit scores you on. Filling them in raises your match on
                    every listing at once.
                @endif
            </p>

            <ul class="list-unstyled d-grid gap-2 mb-0">
                @foreach($checklist as $item)
                    <li class="sz-fit-reason small">
                        <x-icon :name="$item['done'] ? 'check-circle' : 'circle'" :size="16"
                                class="text-{{ $item['done'] ? 'success' : 'secondary' }} mt-1" />
                        <span class="min-w-0">
                            @if($item['done'])
                                <span class="fw-semibold">{{ $item['label'] }}</span>
                            @else
                                <a class="fw-semibold" href="#{{ $anchor($item['anchor']) }}">{{ $item['label'] }}</a>
                                <span class="d-block text-secondary">{{ $item['hint'] }}</span>
                            @endif
                        </span>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@else
    <div {{ $attributes->merge(['class' => 'card sz-profile-progress']) }}>
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="min-w-0 flex-grow-1">
                    <div class="d-flex align-items-baseline gap-2">
                        <span class="fw-semibold">{{ $heading }}</span>
                        <span class="text-secondary small sz-tabular ms-auto">{{ $percent }}%</span>
                    </div>

                    <div class="progress sz-progress-thin my-2" role="progressbar"
                         aria-label="{{ $heading }}" aria-valuenow="{{ $percent }}" aria-valuemin="0" aria-valuemax="100">
                        <div class="progress-bar bg-{{ $tone }}" style="width: {{ $percent }}%"></div>
                    </div>

                    <p class="small text-secondary mb-0">
                        @if($complete)
                            Your profile is complete. Matches are scored on everything you have given us.
                        @else
                            {{-- Names the next thing to do rather than the whole list: one clear
                                 step is what turns a score into an action. --}}
                            Next: add your {{ mb_strtolower($outstanding[0]['label']) }}.
                            @if(count($outstanding) > 1)
                                <span class="text-body-tertiary">
                                    {{ count($outstanding) - 1 }} other
                                    {{ \Illuminate\Support\Str::plural('item', count($outstanding) - 1) }} outstanding.
                                </span>
                            @endif
                        @endif
                    </p>
                </div>

                @unless($complete)
                    <a class="btn btn-sm btn-outline-primary flex-shrink-0"
                       href="{{ route('applicant.profile') }}#{{ $anchor($outstanding[0]['anchor']) }}">Complete profile</a>
                @endunless
            </div>
        </div>
    </div>
@endif
