@extends('layouts.app')

@section('title', 'Saved scholarships')

@section('content')

    <x-page-header title="Saved scholarships"
                   :subtitle="$saved->count() . ' scholarship(s) on your watchlist.'"
                   eyebrow="Student">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('opportunities.index') }}">Find more</a>
        </x-slot:actions>
    </x-page-header>

    @if($saved->isEmpty())
        <div class="card">
            <x-empty-state title="Nothing saved yet"
                           message="Save a scholarship from any listing and it will wait for you here, deadline and all."
                           icon="bookmark"
                           action-label="Browse scholarships"
                           :action-href="route('opportunities.index')" />
        </div>
    @else
        <div class="row g-3 g-lg-4">
            @foreach($saved as $entry)
                @continue($entry->opportunity === null)

                <div class="col-md-6 col-xl-4">
                    <x-scholarship-card :opportunity="$entry->opportunity" :saved="true"
                                        :applied="in_array($entry->opportunity->opportunity_id, $appliedIds, true)"
                                        :accepted="$accepted[$entry->opportunity->opportunity_id] ?? null" />
                </div>
            @endforeach
        </div>
    @endif

@endsection
