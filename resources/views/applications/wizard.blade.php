@extends('layouts.app')

@section('title', 'Apply')

@section('content')

    @php
        /** @var \App\Services\ApplicationReadiness $readiness */
        $missingDocuments = $readiness->missingDocuments();
        $hasForm = $readiness->hasInlineQuestions();
        // Something the profile does not answer that ScholarFit asked about and this page cannot ask in place.
        $unplaced = ($fit && $fit->needsProfileInformation() && ! $readiness->needsDetails() && $missingDocuments === [] && $readiness->details === []);
    @endphp

    <x-page-header :title="'Apply: ' . $opportunity->title"
                   :subtitle="'Awarded by ' . $opportunity->awardingBody()"
                   eyebrow="Application" />

    <div class="row g-4">
        <div class="col-xl-8">

            @if($readiness->needsDetails() || $missingDocuments !== [] || $unplaced)
                {{--
                    Only what is missing, and where it can be supplied. ScholarZim already holds the rest of the profile,
                    so nothing here repeats it: a missing detail or document is asked for in place and saved to the
                    profile (so it is never asked again); something only the profile page can hold - structured
                    results, the education level, a programme - is a link to it, with a way back to this application.
                    The server makes the same checks again when the application is submitted
                    (ApplicationService::submit), so this page only describes them.
                --}}
                <div class="card border-warning" id="missing-details">
                    <div class="card-header bg-warning-subtle">
                        <h2 class="h6 fw-semibold mb-0">A few details are still needed</h2>
                    </div>
                    <div class="card-body">
                        <p class="small text-secondary">
                            Add only what is missing. It is saved to your profile, so you will not be asked again.
                        </p>

                        @if($hasForm)
                            <form method="POST" action="{{ route('applications.details', $opportunity->opportunity_id) }}"
                                  enctype="multipart/form-data" novalidate>
                                @csrf

                                @if($readiness->needsGuardian())
                                    <h3 class="h6 fw-semibold">Parent or guardian</h3>
                                    <p class="small text-secondary">You are under 18, so a parent or guardian has to be involved before you can apply.</p>

                                    @php
                                        $guardianInputs = [
                                            'guardian_name' => ['Guardian full name', 'text'],
                                            'guardian_phone' => ['Guardian phone number', 'tel'],
                                            'guardian_relationship' => ['Relationship to you', 'text'],
                                        ];
                                    @endphp
                                    @foreach($readiness->guardianFields() as $guardianField)
                                        @if($guardianField === 'guardian_confirmed')
                                            <div class="form-check mb-3">
                                                <input class="form-check-input @error('profile.guardian_confirmed') is-invalid @enderror" type="checkbox"
                                                       id="profile-guardian-confirmed" name="profile[guardian_confirmed]" value="1"
                                                       @checked(old('profile.guardian_confirmed')) required>
                                                <label class="form-check-label" for="profile-guardian-confirmed">
                                                    A parent or guardian is aware of and involved in my applications.
                                                </label>
                                                @error('profile.guardian_confirmed')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                                            </div>
                                        @else
                                            @include('applications.partials.missing-field', ['field' => [
                                                'field' => $guardianField, 'label' => $guardianInputs[$guardianField][0],
                                                'kind' => $guardianInputs[$guardianField][1], 'options' => [], 'hint' => null,
                                            ]])
                                        @endif
                                    @endforeach
                                @endif

                                @foreach($readiness->details as $field)
                                    @include('applications.partials.missing-field', ['field' => $field])
                                @endforeach

                                @if($missingDocuments !== [])
                                    <h3 class="h6 fw-semibold">Documents</h3>
                                    @php $needed = count($missingDocuments); @endphp
                                    <p class="small text-secondary">
                                        {{ $needed . ' ' . \Illuminate\Support\Str::plural('document', $needed) . ($needed === 1 ? ' is' : ' are') . ' still needed.' }}
                                        Anything already on your profile is used as it is.
                                    </p>

                                    @php $lost = array_column(array_filter($missingDocuments, fn ($d) => $d['state'] === 'file_missing'), 'label'); @endphp
                                    @if($lost !== [])
                                        <p class="small text-danger" id="lost-documents">
                                            We could not find the file you uploaded earlier for {{ implode(', ', $lost) }}. Please upload it again.
                                        </p>
                                    @endif

                                    @foreach($missingDocuments as $document)
                                        <div class="mb-3">
                                            <label class="form-label" for="documents-{{ $document['type'] }}">
                                                {{ $document['label'] }}<span class="text-danger" aria-hidden="true">*</span>
                                            </label>
                                            <input class="form-control @error('documents.' . $document['type']) is-invalid @enderror" type="file"
                                                   id="documents-{{ $document['type'] }}" name="documents[{{ $document['type'] }}]"
                                                   accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required
                                                   aria-label="Upload {{ $document['label'] }}">
                                            @error('documents.' . $document['type'])
                                                <div class="invalid-feedback d-block">{{ $message }}</div>
                                            @enderror
                                        </div>
                                    @endforeach

                                    <p class="form-text">PDF, Word, JPG or PNG. Saved to your profile; a new upload replaces the current file.</p>
                                @endif

                                <x-submit-button label="Save and continue" busy-label="Saving..." />
                            </form>
                        @endif

                        @if($readiness->profileOnly !== [])
                            <div @class(['mt-4' => $hasForm])>
                                <h3 class="h6 fw-semibold">Add these on your profile</h3>
                                <ul class="list-unstyled d-grid gap-2 mb-0">
                                    @foreach($readiness->profileOnly as $item)
                                        <li class="d-flex flex-wrap align-items-center gap-2">
                                            <span>{{ $item['label'] }}</span>
                                            <a class="btn btn-sm btn-outline-primary ms-auto" href="{{ $readiness->profileLink($item['anchor']) }}">Add on my profile</a>
                                        </li>
                                    @endforeach
                                </ul>
                                <p class="form-text mb-0">Save your profile, then use "Back to my application" at the top of that page.</p>
                            </div>
                        @endif

                        @if($unplaced)
                            <ul class="list-unstyled d-grid gap-2 mb-3">
                                @foreach(\App\Services\ScholarFit\RequirementOutcome::blockingPending($fit->outcomes) as $outcome)
                                    <li>{{ $outcome->message }}</li>
                                @endforeach
                            </ul>
                            <a class="btn btn-primary" href="{{ $readiness->profileLink('') }}">Update my profile</a>
                        @endif

                        <div class="mt-4">
                            <a class="btn btn-outline-secondary" href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                        </div>
                    </div>
                </div>
            @elseif($readiness->storageUnavailable())
                {{--
                    A required document could not be checked because document storage did not answer. It is not said to
                    be missing - it may be fine - and the application is not sent on a requirement nobody could confirm.
                    The server refuses it the same way (DocumentStorageUnavailable).
                --}}
                <div class="card border-warning" id="storage-unavailable">
                    <div class="card-header bg-warning-subtle">
                        <h2 class="h6 fw-semibold mb-0">We could not check your documents</h2>
                    </div>
                    <div class="card-body">
                        <p>
                            Document storage is not responding right now, so we cannot confirm your documents are in place.
                            Nothing has been changed and nothing has been submitted. Please try again in a few minutes.
                        </p>
                        <a class="btn btn-primary" href="{{ route('applications.wizard', $opportunity->opportunity_id) }}">Try again</a>
                        <a class="btn btn-outline-secondary" href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                    </div>
                </div>
            @elseif($readiness->isIneligible())
                {{--
                    The submission would be refused server-side regardless of what is filled in, so the form itself is
                    not shown - a "Submit" button that can never succeed is worse than no button at all. Every
                    requirement is listed, met and unmet alike, each with the value required and the value held, so a
                    refusal reads as a verdict on the one or two things that fell short rather than on the whole profile.
                --}}
                <div class="card border-danger">
                    <div class="card-header bg-danger-subtle">
                        <h2 class="h6 fw-semibold mb-0">NOT ELIGIBLE</h2>
                    </div>
                    <div class="card-body">
                        <p>
                            Your profile does not meet this award's stated requirements.
                            @if($fit && $fit->failsEducationRequirement())
                                {{ \App\Services\ScholarFit\EligibilityResult::EDUCATION_NOT_MET }}
                            @endif
                        </p>

                        <ul class="list-unstyled d-grid gap-2 mb-3">
                            @foreach(\App\Services\ScholarFit\RequirementOutcome::rules($fit->outcomes) as $outcome)
                                <li class="d-flex gap-2 align-items-start">
                                    <x-icon :name="$outcome->passed ? 'check-circle' : 'x-circle'" :size="16"
                                            class="flex-shrink-0 mt-1 {{ $outcome->passed ? 'text-success' : 'text-danger' }}" />
                                    <span class="{{ $outcome->passed ? 'text-secondary' : '' }}">{{ $outcome->message }}</span>
                                </li>
                            @endforeach
                        </ul>

                        {{-- Advisory notes are kept out of the list above and marked apart: an unusual progression is
                             not a requirement anyone failed, and a red cross beside it would say it was. --}}
                        @foreach($fit->advisoryNotes() as $note)
                            <p class="small text-secondary d-flex gap-2 align-items-start">
                                <x-icon name="shield" :size="16" class="flex-shrink-0 mt-1" />
                                <span>{{ $note->message }}</span>
                            </p>
                        @endforeach
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-primary" href="{{ route('applicant.profile', ['apply' => $opportunity->opportunity_id]) }}">Update my profile</a>
                            <a class="btn btn-outline-secondary"
                               href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                        </div>
                    </div>
                </div>
            @else
                {{--
                    Everything this scholarship needs is on file, so what is left is only what is particular to this
                    application: the statement, and anything extra the provider asked for. An eligible applicant is
                    shown what they met first - or told the listing set no requirements, which is not the same
                    statement and must not be dressed up as one. The component draws that distinction.
                --}}
                @if($fit)
                    <x-eligibility-summary :fit="$fit" />
                @endif

                <form method="POST" action="{{ route('applications.submit', $opportunity->opportunity_id) }}"
                      enctype="multipart/form-data" novalidate>
                    @csrf

                    <div class="card mb-4" id="what-we-have">
                        <div class="card-header">
                            <h2 class="h6 fw-semibold mb-0">What ScholarZim will send</h2>
                        </div>
                        <div class="card-body">
                            @php
                                $wizardFields = [
                                    'Name' => auth()->user()->full_name,
                                    'Email' => auth()->user()->email,
                                    'Education level' => \App\Support\EducationLevel::label($profile->education_level),
                                    'Institution' => $profile->institution_name,
                                ];
                                if (\App\Support\EducationLevel::usesFieldOfStudy($profile->education_level)) {
                                    $wizardFields['Field of study'] = $profile->field_of_study;
                                }
                                // The structured results the profile actually holds - the old free-text academic_results
                                // column is legacy and is never filled for a profile that entered its results subject by subject.
                                // A Certificate or Diploma has no academic results to show: its documents are the evidence.
                                if (! \App\Support\EducationLevel::needsNoAcademicDetail($profile->education_level)) {
                                    $recorded = $profile->academicResults()->with('subject')->get()
                                        ->map(fn ($r) => $r->subjectName() . ' ' . $r->result)->all();
                                    $wizardFields['Academic results'] = $recorded !== []
                                        ? implode(', ', $recorded)
                                        : ($profile->degree_classification ?: null);
                                }

                                $required = $readiness->documents->required();
                            @endphp
                            <dl class="row mb-3">
                                @foreach($wizardFields as $label => $value)
                                    <dt class="col-sm-4 text-secondary fw-normal small">{{ $label }}</dt>
                                    <dd class="col-sm-8 fw-semibold">{{ $value ?: 'Not provided' }}</dd>
                                @endforeach

                                <dt class="col-sm-4 text-secondary fw-normal small">Documents</dt>
                                <dd class="col-sm-8">
                                    @if($required === [])
                                        <span class="fw-semibold">No documents are required for your education level.</span>
                                    @else
                                        <span class="d-block fw-semibold">All your required documents are already on file and will be attached automatically.</span>
                                        <span class="small text-secondary">{{ implode(', ', array_column($required, 'label')) }}</span>
                                    @endif
                                </dd>
                            </dl>

                            <a class="btn btn-sm btn-outline-secondary" href="{{ route('applicant.profile', ['apply' => $opportunity->opportunity_id]) }}">
                                Update my profile
                            </a>
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-header">
                            <h2 class="h6 fw-semibold mb-0">Your personal statement</h2>
                        </div>
                        <div class="card-body">
                            <x-form.textarea name="personal_statement" label="Why should you receive this scholarship?"
                                             :rows="10" required
                                             hint="Between 100 and 5,000 characters. Be specific about your goals and circumstances." />
                        </div>
                    </div>

                    <div class="card mb-4">
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label" for="document">Additional document (optional)</label>
                                <input class="form-control @error('document') is-invalid @enderror" type="file"
                                       id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                                <div class="form-text">
                                    Only if this provider asked for something extra. PDF, Word, JPG, or PNG, up to 5 MB.
                                </div>
                                @error('document')
                                    <div class="invalid-feedback d-block">{{ $message }}</div>
                                @enderror
                            </div>

                            <x-form.checkbox name="confirm" id="confirm" required wrapper-class="mb-0"
                                             label="I confirm the information in this application is accurate." />
                        </div>
                    </div>

                    <div class="d-flex flex-wrap gap-2">
                        <x-submit-button label="Submit application" size="lg" busy-label="Submitting..." />
                        <a class="btn btn-outline-secondary btn-lg"
                           href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                    </div>
                </form>
            @endif
        </div>

        <div class="col-xl-4">
            @if($opportunity->deadline)
                <div class="alert alert-secondary small mt-4 mb-0">
                    Applications close {{ $opportunity->deadline->format('d M Y') }}
                    ({{ $opportunity->deadline->diffForHumans() }}).
                </div>
            @endif
        </div>
    </div>

@endsection
