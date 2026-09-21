@extends('layouts.app')

@section('title', 'Find scholarships')

@section('content')

    <x-page-header title="Find scholarships"
                   subtitle="Search open opportunities by education level, field, deadline and location."
                   eyebrow="Discover">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('applicant.recommendations') }}">My matches</a>
        </x-slot:actions>
    </x-page-header>

    {{-- The count belongs with the results, not in the page's subtitle: it
         changes with every filter, and a subtitle that changes is a heading the
         reader has to re-read. --}}
    <p class="text-secondary small" aria-live="polite">
        {{ number_format($opportunities->total()) }}
        {{ \Illuminate\Support\Str::plural('open scholarship', $opportunities->total()) }}
        {{ collect($filters)->except('sort')->filter()->isNotEmpty() ? 'match your filters.' : 'available now.' }}
    </p>

    <x-filter-bar :action="route('opportunities.index')"
                  :filters="$filters"
                  :provider-names="$providerNames"
                  :target-fields="$targetFields"
                  :result-count="$opportunities->total()" />

    @if($opportunities->isEmpty())
        <div class="card">
            <x-empty-state title="No scholarships match those filters"
                           message="Try widening the field of study or clearing the deadline filter."
                           icon="search"
                           action-label="Clear filters"
                           :action-href="route('opportunities.index')" />
        </div>
    @else
        {{-- Same reason as the public listing: the cards are h3 and need a
             section heading between them and the page h1. --}}
        <h2 class="visually-hidden">Search results</h2>

        <div class="row g-3 g-lg-4">
            @foreach($opportunities as $opportunity)
                <div class="col-md-6 col-xxl-4">
                    <x-scholarship-card :opportunity="$opportunity"
                                        :saved="in_array($opportunity->opportunity_id, $savedIds, true)"
                                        :applied="in_array($opportunity->opportunity_id, $appliedIds, true)"
                                        :accepted="$accepted[$opportunity->opportunity_id] ?? null" />
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $opportunities->links() }}
        </div>
    @endif

@endsection
