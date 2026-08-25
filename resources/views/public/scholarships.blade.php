@extends('layouts.public')

@section('title', 'Browse scholarships')

@section('content')
    <div class="container py-4 py-lg-5">

        <x-page-header title="Browse scholarships"
                       :subtitle="number_format($opportunities->total()) . ' open listing(s) matching your filters.'"
                       eyebrow="Public listings" />

        <x-filter-bar :action="route('scholarships.index')"
                      :filters="$filters"
                      :provider-names="$providerNames"
                      :target-fields="$targetFields"
                      :result-count="$opportunities->total()"
                      :can-save-search="true" />

        @if($opportunities->isEmpty())
            <div class="card">
                <x-empty-state title="No scholarships match those filters"
                               message="Try widening the field of study or clearing the deadline filter."
                               icon="search"
                               action-label="Clear filters"
                               :action-href="route('scholarships.index')" />
            </div>
        @else
            <div class="row g-3 g-lg-4">
                @foreach($opportunities as $opportunity)
                    <div class="col-md-6 col-xl-4">
                        <x-scholarship-card :opportunity="$opportunity"
                                            :saved="in_array($opportunity->opportunity_id, $savedIds, true)"
                                            :applied="in_array($opportunity->opportunity_id, $appliedIds, true)" />
                    </div>
                @endforeach
            </div>

            <div class="mt-4">
                {{ $opportunities->links() }}
            </div>
        @endif
    </div>
@endsection
