@extends('layouts.app')

@section('title', 'Browse scholarships')

@section('content')

    <x-page-header title="Browse scholarships"
                   :subtitle="number_format($opportunities->total()) . ' open listing(s) matching your filters.'" />

    <x-filter-bar :action="route('opportunities.index')"
                  :filters="$filters"
                  :provider-names="$providerNames"
                  :target-fields="$targetFields" />

    @if($opportunities->isEmpty())
        <div class="card">
            <x-empty-state title="No scholarships match those filters"
                           message="Try widening the field of study or clearing the deadline filter."
                           icon="search"
                           action-label="Clear filters"
                           :action-href="route('opportunities.index')" />
        </div>
    @else
        <div class="row g-3 g-lg-4">
            @foreach($opportunities as $opportunity)
                <div class="col-md-6 col-xxl-4">
                    <x-scholarship-card :opportunity="$opportunity"
                                        :saved="in_array($opportunity->opportunity_id, $savedIds, true)" />
                </div>
            @endforeach
        </div>

        <div class="mt-4">
            {{ $opportunities->links() }}
        </div>
    @endif

@endsection
