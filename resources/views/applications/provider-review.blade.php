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
                        {{--
                            The applicant as they were when this application was sent: the profile fields,
                            the recorded results and, for a minor, the guardian. Edits made afterwards do
                            not change it (see Application::submittedProfile()).
                        --}}
                        @php
                            $sent = $application->submittedProfile();
                            $sentProfile = $sent['profile'];
                            $profileFields = [
                                'Education level' => \App\Support\EducationLevel::label($sentProfile['education_level'] ?? null),
                                'Institution' => $sentProfile['institution_name'] ?? null,
                            ];
                            if ($sentProfile['uses_field_of_study'] ?? false) {
                                $profileFields['Field of study'] = $sentProfile['field_of_study'] ?? null;
                            }
                            $profileFields['Province'] = $sentProfile['province'] ?? null;
                            $profileFields['Locality'] = $sentProfile['locality'] ?? null;
                            $profileFields['Age'] = $sentProfile['age'] ?? null;
                            // Read from the stored value only - never inferred from the name.
                            $profileFields['Gender'] = \App\Support\Gender::label($sentProfile['gender'] ?? null);
                        @endphp
                        <dl class="row mb-3">
                            @foreach($profileFields as $label => $value)
                                <dt class="col-sm-4 text-secondary fw-normal small">{{ $label }}</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $value ?: 'Not provided' }}</dd>
                            @endforeach
                        </dl>

                        @if($sent['minor'] && $sent['guardian'])
                            <h3 class="sz-eyebrow">Guardian</h3>
                            <p class="small text-secondary">This applicant was under 18 when they applied.</p>
                            <dl class="row mb-3">
                                <dt class="col-sm-4 text-secondary fw-normal small">Name</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $sent['guardian']['name'] ?: 'Not provided' }}</dd>
                                <dt class="col-sm-4 text-secondary fw-normal small">Phone</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $sent['guardian']['phone'] ?: 'Not provided' }}</dd>
                                <dt class="col-sm-4 text-secondary fw-normal small">Relationship</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $sent['guardian']['relationship'] ?: 'Not provided' }}</dd>
                            </dl>
                        @endif

                        {{--
                            The results as recorded, subject by subject. Points are null for a qualification
                            that does not award them, shown as a dash rather than a zero that reads like a bad mark.
                        --}}
                        <h3 class="sz-eyebrow">Academic results</h3>

                        @if(empty($sent['results']))
                            <p class="small text-secondary">This applicant has not recorded any results.</p>
                        @else
                            @foreach(collect($sent['results'])->groupBy('qualification') as $qualification => $group)
                                <p class="small fw-semibold mb-1">{{ $qualification }}</p>
                                <x-data-table :columns="['Subject', 'Result', ['label' => 'Points', 'align' => 'end']]"
                                              size="sm" :hover="false" class="mb-3">
                                    @foreach($group as $result)
                                        <tr>
                                            <x-data-table.cell label="Subject">{{ $result['subject'] }}</x-data-table.cell>
                                            <x-data-table.cell label="Result" class="fw-semibold">{{ $result['result'] }}</x-data-table.cell>
                                            <x-data-table.cell label="Points" align="end" class="sz-tabular">
                                                {{ $result['points'] ?? '-' }}
                                            </x-data-table.cell>
                                        </tr>
                                    @endforeach
                                </x-data-table>
                            @endforeach
                        @endif

                        @if(! empty($sentProfile['biography']))
                            <h3 class="h6 fw-semibold text-uppercase text-secondary small mb-2">Biography</h3>
                            <p>{{ $sentProfile['biography'] }}</p>
                        @endif

                        {{--
                            Every document this provider may open for THIS application: the ones it was
                            submitted with (not whatever the profile holds now), and a "Not provided" row for
                            any the applicant's level asks for and did not send.

                            Before, a missing document simply had no button, which reads the same as a
                            document the reviewer is not allowed to see - and a provider weighing an
                            application needs to know the difference between "they did not supply it" and
                            "I cannot open it here". A recorded file that cannot be found says so instead of
                            offering a link that would 404. The rows come from the controller; no path is
                            ever rendered.
                        --}}
                        <h3 class="sz-eyebrow">Documents</h3>

                        <ul class="list-unstyled d-grid gap-2 mb-0" id="application-documents">
                            @foreach($documents as $document)
                                <li class="d-flex flex-wrap align-items-center gap-2">
                                    @if($document['state'] === 'available')
                                        <x-status-badge label="Uploaded" tone="success" />
                                    @elseif($document['state'] === 'unavailable')
                                        <x-status-badge label="File unavailable" tone="warning" />
                                    @else
                                        <x-status-badge label="Not provided" tone="secondary" />
                                    @endif
                                    <span class="small {{ $document['state'] === 'missing' ? 'text-secondary' : '' }}">
                                        {{ $document['label'] }}
                                    </span>
                                    @if($document['state'] === 'available')
                                        <a class="btn btn-sm btn-outline-secondary ms-auto d-inline-flex align-items-center gap-1"
                                           href="{{ route($document['route'], $application->application_id) }}"
                                           target="_blank" rel="noopener"
                                           aria-label="View {{ $document['label'] }}">
                                            <x-icon name="eye" :size="14" />View
                                        </a>
                                    @elseif($document['state'] === 'unavailable')
                                        <span class="small text-secondary ms-auto">The file could not be found. Ask the applicant to upload it again.</span>
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
                    Guidance, not a verdict. ScholarFit says whether this
                    applicant's profile meets what the listing states it
                    requires; the decision below is entirely the provider's.
                --}}
                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Eligibility check</h2>
                    </div>
                    <div class="card-body">
                        @if($sentEligibility)
                            {{-- The verdict as it stood when the application was sent, with its reasons. --}}
                            @php
                                $verdictLabel = [
                                    'eligible' => ['ELIGIBLE', 'success'],
                                    'not_eligible' => ['NOT ELIGIBLE', 'danger'],
                                    'needs_information' => ['NEEDS INFORMATION', 'warning'],
                                    'needs_review' => ['NEEDS REVIEW', 'warning'],
                                    'insufficient' => ['INSUFFICIENT INFORMATION', 'secondary'],
                                    'no_requirements' => ['NO STATED REQUIREMENTS', 'secondary'],
                                ][$sentEligibility['verdict']] ?? ['UNKNOWN', 'secondary'];
                            @endphp
                            <div class="d-flex flex-wrap align-items-center gap-2">
                                <x-status-badge :label="$verdictLabel[0]" :tone="$verdictLabel[1]" />
                                <span class="small text-secondary">As it stood when they applied.</span>
                            </div>
                            @if($sentEligibility['rules'])
                                <ul class="list-unstyled d-grid gap-1 mt-2 mb-0 small">
                                    @foreach($sentEligibility['rules'] as $rule)
                                        <li class="d-flex gap-2 align-items-start">
                                            <x-icon :name="$rule['status'] === 'pending' ? 'circle' : ($rule['status'] === 'met' ? 'check-circle' : 'x-circle')" :size="14"
                                                    class="flex-shrink-0 mt-1 {{ $rule['status'] === 'pending' ? 'text-warning' : ($rule['status'] === 'met' ? 'text-success' : 'text-danger') }}" />
                                            <span class="{{ $rule['status'] === 'met' ? 'text-secondary' : '' }}">{{ $rule['message'] }}</span>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                            @if($profileChanged)
                                <p class="small text-warning-emphasis mt-2 mb-0">The applicant's profile has changed since they applied.</p>
                            @endif
                        @else
                            <x-eligibility-summary :fit="$fit" variant="compact" audience="provider" class="mb-0" />
                        @endif

                        <p class="small text-secondary mt-3 mb-0">
                            A guide to whether the profile meets this listing's stated requirements. The decision is yours.
                            @unless($sentEligibility)
                                Worked out from the applicant's profile as it is today; the details on the left are as submitted.
                            @endunless
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
                        @if($application->isReopened())
                            <div class="alert alert-info small" role="note">
                                You withdrew your {{ strtolower(\App\Support\ApplicationStatus::displayLabel($application->reopened_from)) }}
                                decision on {{ $application->reopened_at->format('d M Y') }}. Decide again below.
                                <span class="d-block mt-1">Your reason: {{ $application->reopen_reason }}</span>
                            </div>
                        @endif

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
                                The applicant is notified at once and sees your reason. Accepting grants the scholarship.
                                A mistaken decision can be withdrawn, which reopens the application.
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

                        @if($canReopen)
                            {{--
                                Withdrawing a decision: back to Pending, so a fresh one can be made. Needs a reason
                                (the applicant is told it) and a confirmation, and says plainly what withdrawing an
                                acceptance undoes. The server checks all of it again; this only keeps the provider
                                from reaching for it by accident.
                            --}}
                            <hr>
                            <x-confirm-dialog id="reopen-decision"
                                              :action="route('provider.applications.reopen', $application->application_id)"
                                              :title="$application->isAccepted() ? 'Withdraw your acceptance?' : 'Withdraw your rejection?'"
                                              trigger-label="Withdraw decision and reopen"
                                              trigger-class="btn btn-outline-secondary w-100"
                                              trigger-icon="clock-history"
                                              confirm-label="Withdraw decision"
                                              :tone="$application->isAccepted() ? 'danger' : 'warning'"
                                              :message="$application->isAccepted()
                                                  ? 'This reverses the scholarship award. The applicant is told their acceptance no longer stands, the award slot is released, and the application goes back to Pending until you decide again. Your original decision stays in the audit history.'
                                                  : 'The application goes back to Pending so you can decide again, and the applicant is told. Your original decision stays in the audit history.'">
                                <x-form.textarea name="reason" :rows="3" required id="reopen-reason" bag="reopen-decision"
                                                 label="Why are you withdrawing this decision?"
                                                 hint="The applicant sees this exactly as you write it." />
                            </x-confirm-dialog>
                        @endif
                    @endif
                </div>
            </div>

            @if($decisionHistory->count() > 1 || $application->isReopened())
                <div class="card mt-4" id="decision-history">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Decision history</h2>
                    </div>
                    <ul class="list-group list-group-flush small">
                        @foreach($decisionHistory as $entry)
                            <li class="list-group-item">
                                <span class="fw-semibold">
                                    @if($entry['kind'] === 'reopened')
                                        Withdrew the {{ $entry['from'] }} decision
                                    @else
                                        {{ ucfirst($entry['kind']) }}
                                    @endif
                                </span>
                                <span class="text-secondary">&middot; {{ $entry['at']?->format('d M Y H:i') }}@if($entry['by']) &middot; {{ $entry['by'] }}@endif</span>
                                @if($entry['reason'])
                                    <span class="d-block">{{ $entry['reason'] }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
        </div>
    </div>

@endsection
