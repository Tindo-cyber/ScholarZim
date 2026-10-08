@props(['profile'])

@php
    /**
     * Two things about the profile as it stands today: where it disagrees with itself, and
     * whether its level is due a yearly check. Warnings never block anything; the
     * confirmation is one click when nothing has changed.
     */
    $warnings = \App\Services\ProfileDataQuality::warnings($profile);
    $confirm = \App\Services\ProfileDataQuality::levelNeedsConfirming($profile);
@endphp

@if($confirm)
    <div {{ $attributes->merge(['class' => 'alert alert-info']) }} role="note" id="confirm-level-prompt">
        <div class="fw-semibold mb-1">Is {{ \App\Support\EducationLevel::label($profile->education_level) }} still your current level?</div>
        <p class="small mb-2">
            Scholarships are matched to your level, and levels change. Confirm it so your matches stay right.
        </p>
        <div class="d-flex flex-wrap gap-2">
            <form method="POST" action="{{ route('applicant.profile.confirmLevel') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-primary">Yes, that is still right</button>
            </form>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('applicant.profile') }}#education_level">No, change it</a>
        </div>
    </div>
@endif

@if($warnings !== [])
    <div {{ $attributes->merge(['class' => 'alert alert-warning']) }} role="alert" id="profile-quality-warnings">
        <div class="fw-semibold mb-1">Check your profile</div>
        <ul class="small mb-0">
            @foreach($warnings as $warning)
                <li>
                    {{ $warning['message'] }}
                    <a href="{{ route('applicant.profile') }}#{{ $warning['field'] }}">Fix this</a>
                </li>
            @endforeach
        </ul>
    </div>
@endif
