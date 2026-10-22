@extends('layouts.app')

@section('title', 'Edit scholarship')

@section('content')

    @php
        $impact = \App\Support\EditImpact::untouched($opportunity->moderation_status);
    @endphp

    <x-page-header title="Edit scholarship"
                   subtitle="Some changes take a live listing offline for review and some do not. The notice above the Save button tells you which, before you save."
                   eyebrow="Provider" />

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('opportunities.update', $opportunity->opportunity_id) }}" novalidate>
                @csrf
                @method('PUT')

                @include('opportunities.partials.listing-fields', ['opportunity' => $opportunity, 'defaults' => []])

                <div class="card mb-4">
                    <div class="card-header">
                        <h2 class="h6 fw-semibold mb-0">Reason for this change</h2>
                    </div>
                    <div class="card-body">
                        <x-form.textarea name="reason" label="What changed and why" :rows="3" required
                                         hint="Recorded in the review trail, e.g. &quot;Updated award amount&quot;." />
                    </div>
                </div>

                <div id="edit-impact"
                     data-url="{{ route('opportunities.editImpact', $opportunity->opportunity_id) }}"
                     class="alert alert-{{ $impact['tone'] }} mb-4"
                     role="status" aria-live="polite">
                    <div class="fw-semibold" data-impact-headline>{{ $impact['headline'] }}</div>
                    <div class="small" data-impact-detail>{{ $impact['detail'] }}</div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <x-submit-button :label="$impact['button']" size="lg" busy-label="Saving..." />
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
                    <p class="small text-secondary mb-0">
                        Changing the title, description, level, field, funding, country, award, eligibility or subject
                        requirements takes a live listing offline until an administrator approves it again. Other edits
                        do not. To move the deadline later, use "Extend deadline" on the dashboard.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
