@extends('layouts.app')

@section('title', 'Post a scholarship')

@section('content')

    <x-page-header :title="isset($draft) ? 'Edit draft' : (isset($duplicateOf) ? 'Post another intake' : 'Post a scholarship')"
                   subtitle="Listings go live once an administrator has reviewed them."
                   eyebrow="Provider" />

    @isset($draft)
        {{--
            A draft is the provider's alone: not reviewed, not public, nobody told. Submitting
            it is the first time any of that happens, and the full checks run then.
        --}}
        <div class="alert alert-secondary" role="note" id="draft-notice">
            <strong>This is a draft.</strong> Only you can see it. Nothing is checked until you submit it for review.
        </div>
    @endisset

    @if(session('draftNotKept'))
        {{--
            What the draft could not hold, field by field. Saved with whatever else could be, so
            nothing was lost silently: this is the list of what was left out and why.
        --}}
        <div class="alert alert-warning" role="alert" id="draft-not-kept">
            <strong>Not saved</strong> - these could not be kept in the draft:
            <ul class="mb-0 mt-2">
                @foreach(session('draftNotKept') as $lost)
                    <li><strong>{{ $lost['field'] }}</strong>: {{ $lost['reason'] }}</li>
                @endforeach
            </ul>
        </div>
    @endif

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

    @isset($pastedReport)
        {{--
            What the pasted advert filled in, for this one visit. Nothing is saved: the form below is the
            provider's to read, correct and submit like any other.
        --}}
        <div class="alert alert-info" role="note" id="pasted-report">
            <strong>Filled in from your pasted text.</strong> Nothing has been saved. Check every field before you
            submit: a reading of an advert can be wrong.
            @if(count($pastedReport['understood']))
                <div class="small mt-2"><strong>Understood from your text:</strong> {{ implode(', ', $pastedReport['understood']) }}.</div>
            @endif
            @if(count($pastedReport['notKept']))
                <div class="small mt-2"><strong>Not used</strong> (left blank for you to fill in):</div>
                <ul class="small mb-0">
                    @foreach($pastedReport['notKept'] as $lost)
                        <li><strong>{{ $lost['field'] }}</strong>: {{ $lost['reason'] }}</li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endisset

    @if(($pasteEnabled ?? false) && ! isset($draft) && ! isset($duplicateOf))
        <details class="card mb-4" id="paste-advert" @if($errors->has('pasted_text') || old('pasted_text')) open @endif>
            <summary class="card-header fw-semibold">Have the advert as text? Paste it to fill this in</summary>
            <div class="card-body">
                <form method="POST" action="{{ route('opportunities.prefill') }}">
                    @csrf
                    <label class="form-label" for="pasted_text">The advert's text</label>
                    <textarea class="form-control @error('pasted_text') is-invalid @enderror" id="pasted_text" name="pasted_text"
                              rows="6" maxlength="{{ (int) config('scholarzim.prefill.max_chars', 10000) }}">{{ old('pasted_text') }}</textarea>
                    @error('pasted_text')
                        <div class="invalid-feedback d-block">{{ $message }}</div>
                    @enderror
                    <div class="form-text mb-3">
                        The text you paste is sent to Anthropic's AI service to be read, so do not paste anything private.
                        It only fills in this form: nothing is saved or submitted until you do that yourself.
                    </div>
                    <button type="submit" class="btn btn-outline-primary">Fill in the form from this text</button>
                </form>
            </div>
        </details>
    @endif

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('opportunities.store') }}" novalidate>
                @csrf
                @isset($draft)
                    <input type="hidden" name="draft_id" value="{{ $draft->opportunity_id }}">
                @endisset

                {{-- A new listing, or a copy of one ($prefill) - see OpportunityController::duplicate. --}}
                @include('opportunities.partials.listing-fields', ['opportunity' => $prefill ?? null, 'defaults' => $defaults ?? []])

                <div class="d-flex flex-wrap gap-2">
                    <x-submit-button label="Submit for review" size="lg" busy-label="Submitting..." />
                    {{-- Saves what can be saved with no further checks. Works with JavaScript off: it is just a different formaction. --}}
                    <button type="submit" class="btn btn-outline-secondary btn-lg" id="save-draft"
                            formaction="{{ route('opportunities.draft.save') }}" formnovalidate>
                        Save draft
                    </button>
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
