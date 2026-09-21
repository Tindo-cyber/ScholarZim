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
        {{-- The same grid as the two listing pages: the cards title themselves h3
             and need a section heading between them and the page h1. This one
             escaped the sweep only because the test account had saved nothing,
             so the empty state rendered instead of the cards. --}}
        <h2 class="visually-hidden">Saved scholarships</h2>

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
