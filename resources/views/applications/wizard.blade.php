@extends('layouts.app')

@section('title', 'Apply')

@section('content')

    <x-page-header :title="'Apply: ' . $opportunity->title"
                   :subtitle="'Awarded by ' . $opportunity->awardingBody()"
                   eyebrow="Application" />

    <div class="row g-4">
        <div class="col-xl-8">

            @if(! $profile->isComplete())
                {{--
                    Gate one, before gate two is even worth showing: ScholarFit
                    cannot check a listing's requirements against information
                    the profile does not have yet, so the eligibility card
                    below is not rendered until this one clears - the same
                    order ApplicationService enforces server-side.
                --}}
                <div class="card border-danger">
                    <div class="card-header bg-danger-subtle">
                        <h2 class="h6 fw-semibold mb-0">COMPLETE YOUR PROFILE FIRST</h2>
                    </div>
                    <div class="card-body">
                        <p>ScholarFit needs the rest of your profile before it can check this scholarship's requirements.</p>
                        <ul class="mb-3">
                            @foreach($profile->missingFields() as $field)
                                <li>{{ $field }}</li>
                            @endforeach
                        </ul>
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-primary" href="{{ route('applicant.profile') }}">Complete my profile</a>
                            <a class="btn btn-outline-secondary"
                               href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                        </div>
                    </div>
                </div>
            @elseif($fit && ! $fit->meetsRequirements())
                {{--
                    The submission would be refused server-side regardless of
                    what is filled in below, so the form itself is not shown -
                    a "Submit" button that can never succeed is worse than no
                    button at all.
                --}}
                <div class="card border-danger">
                    <div class="card-header bg-danger-subtle">
                        <h2 class="h6 fw-semibold mb-0">NOT ELIGIBLE</h2>
                    </div>
                    <div class="card-body">
                        <p>Your profile does not meet this award's stated requirements.</p>
                        {{--
                            Every requirement is listed, met and unmet alike, each with the
                            value required and the value held. Showing only the failures made
                            a refusal read as a verdict on the whole profile rather than on
                            the one or two things that actually fell short.
                        --}}
                        <ul class="list-unstyled d-grid gap-2 mb-3">
                            @foreach(\App\Services\ScholarFit\RequirementOutcome::rules($fit->breakdown->requirementOutcomes) as $outcome)
                                <li class="d-flex gap-2 align-items-start">
                                    <x-icon :name="$outcome->passed ? 'check-circle' : 'x-circle'" :size="16"
                                            class="flex-shrink-0 mt-1 {{ $outcome->passed ? 'text-success' : 'text-danger' }}" />
                                    <span class="{{ $outcome->passed ? 'text-secondary' : '' }}">{{ $outcome->message }}</span>
                                </li>
                            @endforeach
                        </ul>

                        {{--
                            Advisory notes are kept out of the list above and marked apart.
                            An unusual progression is not a requirement anyone failed, and
                            showing it beside the rules with a red cross would say it was.
                        --}}
                        @foreach($fit->breakdown->advisoryNotes() as $note)
                            <p class="small text-secondary d-flex gap-2 align-items-start">
                                <x-icon name="shield" :size="16" class="flex-shrink-0 mt-1" />
                                <span>{{ $note->message }}</span>
                            </p>
                        @endforeach
                        <div class="d-flex flex-wrap gap-2">
                            <a class="btn btn-primary" href="{{ route('applicant.profile') }}">Update my profile</a>
                            <a class="btn btn-outline-secondary"
                               href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                        </div>
                    </div>
                </div>
            @else
            {{--
                An eligible applicant is shown what they met before they fill
                anything in - or told the listing set no requirements, which is
                not the same statement and must not be dressed up as one. The
                component draws that distinction; this page only decides where
                it sits.
            --}}
            @if($fit)
                <x-eligibility-summary :fit="$fit" />
            @endif

            <form method="POST" action="{{ route('applications.submit', $opportunity->opportunity_id) }}"
                  enctype="multipart/form-data" novalidate>
                @csrf

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Step 1 &mdash; Confirm your details</h2>
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
                            $wizardFields['Academic results'] = $profile->academic_results;
                        @endphp
                        <dl class="row mb-3">
                            @foreach($wizardFields as $label => $value)
                                <dt class="col-sm-4 text-secondary fw-normal small">{{ $label }}</dt>
                                <dd class="col-sm-8 fw-semibold">{{ $value ?: 'Not provided' }}</dd>
                            @endforeach
                        </dl>

                        <a class="btn btn-sm btn-outline-secondary" href="{{ route('applicant.profile') }}">
                            Update my profile
                        </a>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Step 2 &mdash; Your personal statement</h2>
                    </div>
                    <div class="card-body">
                        <x-form.textarea name="personal_statement" label="Why should you receive this scholarship?"
                                         :rows="10" required
                                         hint="Between 100 and 5,000 characters. Be specific about your goals and circumstances." />
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Step 3 &mdash; Documents</h2>
                    </div>
                    <div class="card-body">
                        @if(empty($missingDocumentTypes))
                            <div class="alert alert-success small">
                                All your required documents are already on file and will be attached automatically.
                            </div>
                        @else
                            <div class="alert alert-warning small">
                                These are required for your education level but missing from your profile. Upload them
                                here and they will be saved to your profile too.
                            </div>

                            @foreach($missingDocumentTypes as $type)
                                @php $label = \App\Models\ApplicantProfile::DOCUMENT_LABELS[$type]; @endphp
                                <div class="mb-3">
                                    <label class="form-label" for="documents-{{ $type }}">
                                        {{ $label }}<span class="text-danger" aria-hidden="true">*</span>
                                    </label>
                                    <input class="form-control @error('documents.' . $type) is-invalid @enderror" type="file"
                                           id="documents-{{ $type }}" name="documents[{{ $type }}]"
                                           accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required
                                           aria-label="Upload {{ $label }}">
                                    @error('documents.' . $type)
                                        <div class="invalid-feedback d-block">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endforeach
                        @endif

                        <div class="mb-3">
                            <label class="form-label" for="document">Additional document (optional)</label>
                            <input class="form-control @error('document') is-invalid @enderror" type="file"
                                   id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div class="form-text">
                                Anything extra this provider asked for. PDF, Word, JPG, or PNG, up to 5 MB.
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
            {{--
                No percentage when a stated requirement is not met - the main
                column already explains why in full, and a number next to
                "you cannot apply" only invites the reader to argue with it.
            --}}
            @if($fit && $fit->meetsRequirements())
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <x-match-score :score="$fit->matchScore" :label="$fit->breakdown->confidenceLabel" size="lg" />
                        <p class="small text-secondary mt-3 mb-0">{{ $fit->breakdown->explanation }}</p>
                    </div>
                </div>

                <x-score-fixes :fit="$fit" variant="alert" />
            @endif

            @if($opportunity->deadline)
                <div class="alert alert-secondary small mt-4 mb-0">
                    Applications close {{ $opportunity->deadline->format('d M Y') }}
                    ({{ $opportunity->deadline->diffForHumans() }}).
                </div>
            @endif
        </div>
    </div>

@endsection
