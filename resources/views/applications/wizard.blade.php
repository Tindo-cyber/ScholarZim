@extends('layouts.app')

@section('title', 'Apply')

@section('content')

    <x-page-header :title="'Apply: ' . $opportunity->title"
                   :subtitle="'Awarded by ' . $opportunity->awardingBody()"
                   eyebrow="Application" />

    <div class="row g-4">
        <div class="col-xl-8">

            <form method="POST" action="{{ route('applications.submit', $opportunity->opportunity_id) }}"
                  enctype="multipart/form-data" novalidate>
                @csrf

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Step 1 &mdash; Confirm your details</h2>
                    </div>
                    <div class="card-body">
                        <dl class="row mb-3">
                            @foreach([
                                'Name' => auth()->user()->full_name,
                                'Email' => auth()->user()->email,
                                'Education level' => $profile->education_level,
                                'Institution' => $profile->institution_name,
                                'Field of study' => $profile->field_of_study,
                                'Academic results' => $profile->academic_results,
                            ] as $label => $value)
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
                        <h2 class="h6 fw-semibold mb-0">Step 3 &mdash; Supporting document</h2>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <label class="form-label" for="document">Attach a document (optional)</label>
                            <input class="form-control @error('document') is-invalid @enderror" type="file"
                                   id="document" name="document" accept=".pdf,.jpg,.jpeg,.png,.doc,.docx">
                            <div class="form-text">
                                PDF, Word, JPG, or PNG, up to 5 MB. Your profile documents are shared automatically.
                            </div>
                            @error('document')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>

                        <div class="form-check mb-0">
                            <input class="form-check-input @error('confirm') is-invalid @enderror" type="checkbox"
                                   name="confirm" id="confirm" value="1" @checked(old('confirm')) required>
                            <label class="form-check-label" for="confirm">
                                I confirm the information in this application is accurate.
                            </label>
                            @error('confirm')
                                <div class="invalid-feedback d-block">{{ $message }}</div>
                            @enderror
                        </div>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-lg" type="submit">Submit application</button>
                    <a class="btn btn-outline-secondary btn-lg"
                       href="{{ route('scholarships.show', $opportunity->opportunity_id) }}">Back to listing</a>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            @if($fit)
                <div class="card mb-4">
                    <div class="card-body text-center">
                        <x-match-score :score="$fit->matchScore" :label="$fit->breakdown->confidenceLabel" size="lg" />
                        <p class="small text-secondary mt-3 mb-0">{{ $fit->breakdown->explanation }}</p>
                    </div>
                </div>

                @if($fit->breakdown->missingRequirements)
                    <div class="card border-warning">
                        <div class="card-header bg-warning-subtle">
                            <h2 class="h6 fw-semibold mb-0">Before you submit</h2>
                        </div>
                        <div class="card-body">
                            <ul class="small mb-0 ps-3">
                                @foreach($fit->breakdown->missingRequirements as $item)
                                    <li>{{ $item }}</li>
                                @endforeach
                            </ul>
                        </div>
                    </div>
                @endif
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
