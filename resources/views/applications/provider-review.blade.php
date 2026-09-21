@extends('layouts.app')

@section('title', 'Review application')

@section('content')

    <x-page-header :title="$application->user?->displayName() ?? 'Applicant'"
                   :subtitle="'Applied to ' . ($application->opportunity?->title ?? 'a listing')"
                   eyebrow="Review application">
        <x-slot:actions>
            <a class="btn btn-outline-secondary" href="{{ route('provider.applications') }}">Back to inbox</a>
        </x-slot:actions>
    </x-page-header>

    <div class="card mb-4">
        <div class="card-body py-4">
            <x-timeline :stages="$timeline" />
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-8">

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Personal statement</h2>
                </div>
                <div class="card-body">
                    @if($application->personal_statement)
                        {!! nl2br(e($application->personal_statement)) !!}
                    @else
                        <p class="text-secondary mb-0">
                            Quick application &mdash; no statement was submitted. The profile below is what
                            this applicant provided.
                        </p>
                    @endif
                </div>
            </div>

            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Applicant profile</h2>
                </div>
                <div class="card-body">
                    @if($applicantProfile)
                        @php
                            $profileFields = [
                                'Education level' => \App\Support\EducationLevel::label($applicantProfile->education_level),
                                'Institution' => $applicantProfile->institution_name,
                            ];
                            if (\App\Support\EducationLevel::usesFieldOfStudy($applicantProfile->education_level)) {
                                $profileFields['Field of study'] = $applicantProfile->field_of_study;
                            }
                            $profileFields['Province'] = $applicantProfile->province;
                            $profileFields['Locality'] = $applicantProfile->locality;
                            $profileFields['Age'] = $applicantProfile->age();
                        @endphp
                        <dl class="row mb-3">
                            @foreach($profileFields as $label => $value)
                                <dt class="col-sm-4 text-secondary fw-normal small">{{ $label }}</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $value ?: 'Not provided' }}</dd>
                            @endforeach
                        </dl>

                        {{--
                            The results as recorded, subject by subject.

                            This row used to print applicant_profiles.academic_results - the
                            free-text column the structured model replaced - so a provider
                            reviewing an application saw either a stale sentence or nothing
                            at all, while the actual grades the eligibility check ran against
                            sat one relation away. Points come from the result's own
                            points(), which is null for a qualification that does not award
                            them rather than a zero that reads like a bad mark.
                        --}}
                        @php $results = $applicantProfile->academicResults; @endphp

                        <h3 class="sz-eyebrow">Academic results</h3>

                        @if($results->isEmpty())
                            <p class="small text-secondary">This applicant has not recorded any results.</p>
                        @else
                            @foreach($results->groupBy(fn ($result) => $result->qualificationName()) as $qualification => $group)
                                <p class="small fw-semibold mb-1">{{ $qualification }}</p>
                                <x-data-table :columns="['Subject', 'Result', ['label' => 'Points', 'align' => 'end']]"
                                              size="sm" :hover="false" class="mb-3">
                                    @foreach($group as $result)
                                        <tr>
                                            <x-data-table.cell label="Subject">{{ $result->subjectName() }}</x-data-table.cell>
                                            <x-data-table.cell label="Result" class="fw-semibold">{{ $result->result }}</x-data-table.cell>
                                            <x-data-table.cell label="Points" align="end" class="sz-tabular">
                                                {{ $result->points() ?? '-' }}
                                            </x-data-table.cell>
                                        </tr>
                                    @endforeach
                                </x-data-table>
                            @endforeach
                        @endif

                        @if($applicantProfile->biography)
                            <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-2">Biography</h3>
                            <p>{{ $applicantProfile->biography }}</p>
                        @endif

                        {{--
                            Every document this provider may open, listed whether it is there
                            or not.

                            Before, a missing document simply had no button, which reads the
                            same as a document the reviewer is not allowed to see - and a
                            provider weighing an application needs to know the difference
                            between "they did not supply it" and "I cannot open it here".
                            The three routes below are exactly the three a provider is
                            authorised for; nothing about that authorisation changed.
                        --}}
                        <h3 class="sz-eyebrow">Documents</h3>

                        <ul class="list-unstyled d-grid gap-2 mb-0">
                            @foreach([
                                ['label' => $application->document_filename ?: 'Application attachment',
                                 'present' => (bool) $application->document_filename,
                                 'route' => 'files.applicationDocument'],
                                ['label' => 'Results certificate',
                                 'present' => $applicantProfile->hasResultsCertificate(),
                                 'route' => 'files.applicantResults'],
                                ['label' => 'Academic transcript',
                                 'present' => $applicantProfile->hasTranscript(),
                                 'route' => 'files.applicantTranscript'],
                            ] as $document)
                                <li class="d-flex flex-wrap align-items-center gap-2">
                                    <x-status-badge :label="$document['present'] ? 'Uploaded' : 'Not provided'"
                                                    :tone="$document['present'] ? 'success' : 'secondary'" />
                                    <span class="small {{ $document['present'] ? '' : 'text-secondary' }}">
                                        {{ $document['label'] }}
                                    </span>
                                    @if($document['present'])
                                        <a class="btn btn-sm btn-outline-secondary ms-auto d-inline-flex align-items-center gap-1"
                                           href="{{ route($document['route'], $application->application_id) }}"
                                           target="_blank" rel="noopener">
                                            <x-icon name="eye" :size="14" />View
                                        </a>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    @else
                        <p class="text-secondary mb-0">This applicant has not completed a profile.</p>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-4">

            @if($fit)
                {{--
                    Guidance, not a verdict. ScholarFit says how well this
                    applicant's profile lines up with what the listing asks for;
                    the decision below is entirely the provider's.
                --}}
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">ScholarFit match</h2>
                    </div>
                    <div class="card-body">
                        {{--
                            Eligibility first, score second - the same order the applicant
                            sees it in, and the same component. A provider was previously
                            shown a percentage with no statement of whether the applicant
                            actually meets the rules the listing states, which is the one
                            thing the engine can answer definitively.
                        --}}
                        <x-eligibility-summary :fit="$fit" variant="compact" class="mb-3" />

                        <div class="text-center">
                            <x-match-score :score="$fit->matchScore" :label="$fit->breakdown->confidenceLabel" />
                        </div>

                        <p class="small text-secondary mt-3">{{ $fit->breakdown->explanation }}</p>

                        <x-score-breakdown :fit="$fit" />

                        <p class="small text-secondary mt-3 mb-0">
                            A guide to how well the profile fits this listing. The decision is yours.
                        </p>
                    </div>
                </div>
            @endif

            <div class="card position-sticky" style="top: 5.5rem;">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h2 class="h6 fw-semibold mb-0">Decision</h2>
                    <x-status-badge :label="$application->statusLabel()" :tone="$application->statusTone()" />
                </div>

                <div class="card-body">
                    @if($canDecide)
                        {{--
                            One form, two buttons. Accepting is granting the
                            scholarship, so there is nothing to do afterwards -
                            and both outcomes need the reason the applicant is
                            shown verbatim.
                        --}}
                        <form method="POST" action="{{ route('provider.applications.review', $application->application_id) }}">
                            @csrf

                        {{--
                            No confirm dialog on these two buttons, deliberately.

                            A decision already takes a typed reason before either button will
                            submit, which is a more deliberate act than clicking through a
                            dialog - and the form works without JavaScript, which a modal
                            would not. What was missing was the consequence, said plainly.
                        --}}
                        <div class="alert alert-warning small d-flex gap-2" role="note">
                            <x-icon name="shield" :size="16" class="flex-shrink-0 mt-1" />
                            <div>
                                A decision is final. The applicant is notified straight away and sees
                                your reason word for word. Accepting is granting the scholarship.
                            </div>
                        </div>

                            <x-form.textarea name="reason" label="Reason for your decision" :rows="4" required
                                             hint="The applicant sees this exactly as you write it." />

                            <div class="d-grid gap-2">
                                {{-- Two submits in one form, each with its own value. The busy
                                     state follows event.submitter, so only the one that was
                                     pressed spins. --}}
                                <x-submit-button tone="success" icon="check-circle" label="Accept"
                                                 busy-label="Accepting..."
                                                 name="status"
                                                 :value="\App\Support\ApplicationStatus::ACCEPTED" />
                                <x-submit-button tone="outline-danger" icon="x-circle" label="Reject"
                                                 busy-label="Rejecting..."
                                                 name="status"
                                                 :value="\App\Support\ApplicationStatus::REJECTED" />
                            </div>
                        </form>
                    @else
                        <p class="mb-0">
                            <span class="fw-semibold d-block mb-1">
                                {{ $application->isAccepted() ? 'Scholarship granted' : $application->statusLabel() }}
                            </span>
                            <span class="text-secondary small">
                                @if($application->isDecided())
                                    Decided {{ $application->decided_at?->format('d M Y') ?? 'recently' }}.
                                    This is final &mdash; the application cannot be changed again.
                                @else
                                    The applicant withdrew this application, so there is no decision to make.
                                @endif
                            </span>
                        </p>

                        @if($application->decision_reason)
                            <hr>
                            <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-1">Reason you gave</h3>
                            <p class="mb-0">{{ $application->decision_reason }}</p>
                        @endif
                    @endif
                </div>
            </div>
        </div>
    </div>

@endsection
