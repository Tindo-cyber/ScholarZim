@extends('layouts.app')

@section('title', 'Post a scholarship')

@section('content')

    <x-page-header :title="isset($duplicateOf) ? 'Post another intake' : 'Post a scholarship'"
                   subtitle="Listings go live once an administrator has reviewed them."
                   eyebrow="Provider" />

    @isset($duplicateOf)
        {{--
            A copy fills the form; it is not saved. The deadline is blank and the title has the
            next year in it, and the copy goes through review like any new listing.
        --}}
        <div class="alert alert-info" role="note">
            Copied from <strong>{{ $duplicateOf->title }}</strong>. Set the new deadline and check
            the details, then submit. It is a new listing and is reviewed like one.
        </div>
    @endisset

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('opportunities.store') }}" novalidate>
                @csrf

                {{-- A new listing, or a copy of one ($prefill) - see OpportunityController::duplicate. --}}
                @include('opportunities.partials.listing-fields', ['opportunity' => $prefill ?? null, 'defaults' => $defaults ?? []])

                <div class="d-flex flex-wrap gap-2">
                    <x-submit-button label="Submit for review" size="lg" busy-label="Submitting..." />
                    <button type="submit" class="btn btn-outline-secondary btn-lg"
                            formaction="{{ route('opportunities.preview') }}" formmethod="post" formtarget="_blank" formnovalidate>
                        Preview
                    </button>
                    <a class="btn btn-outline-secondary btn-lg" href="{{ route('provider.dashboard') }}">Cancel</a>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">What happens next</h2>
                </div>
                <div class="card-body">
                    <ol class="list-unstyled d-grid gap-3 mb-0 small">
                        <li class="d-flex gap-3">
                            <span class="sz-step-number flex-shrink-0" style="width:2rem;height:2rem;">1</span>
                            <span>Your listing is queued for administrator review. It is not public yet.</span>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="sz-step-number flex-shrink-0" style="width:2rem;height:2rem;">2</span>
                            <span>Once approved, it appears in search and students matching it are notified.</span>
                        </li>
                        <li class="d-flex gap-3">
                            <span class="sz-step-number flex-shrink-0" style="width:2rem;height:2rem;">3</span>
                            <span>Applications land in your inbox, where you can review and decide on each one.</span>
                        </li>
                    </ol>

                    <hr>

                    <p class="small text-secondary mb-0">
                        The fields you fill in here feed ScholarFit directly: education level, field of study,
                        province/locality, and deadline are what students are checked against.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
