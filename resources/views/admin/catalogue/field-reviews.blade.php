@extends('layouts.app')

@section('title', 'Field reviews')

@section('content')

    <x-page-header title="Field reviews"
                   subtitle="Programmes whose names suggest a more exact field than the one they are filed under."
                   eyebrow="Governance" />

    @include('admin.catalogue.nav', ['active' => 'reviews'])

    {{--
        Nothing here has been changed. A "refine" narrows a programme to a detailed field inside the one it is in; a "move" files
        it under a different field. Accepting writes the change and an audit line; keeping it as it is leaves the programme alone
        and the proposal is not raised again.
    --}}
    @forelse($reviews as $review)
        <div class="card mb-3">
            <div class="card-body d-flex flex-wrap justify-content-between gap-3">
                <div>
                    <div class="fw-semibold">{{ $review->programme->name }}</div>
                    <div class="small text-secondary">{{ $review->programme->levelLabel() }}</div>
                    <div class="mt-2">
                        <span class="text-secondary">{{ $review->from?->code }} {{ $review->from?->label() }}</span>
                        &rarr; <strong>{{ $review->to->code }} {{ $review->to->label() }}</strong>
                        <span class="badge text-bg-light border ms-1">{{ $review->kind === 'move' ? 'a different field' : 'more exact' }}</span>
                        <span class="badge text-bg-light border">{{ $review->confidence }} confidence</span>
                    </div>
                    <div class="small text-secondary mt-1">{{ $review->reason }}</div>
                </div>
                <div class="d-flex gap-2 align-items-start">
                    <form method="POST" action="{{ route('admin.catalogue.fieldReviews.accept', $review->id) }}">
                        @csrf
                        <button class="btn btn-sm btn-success" type="submit">Change it</button>
                    </form>
                    <form method="POST" action="{{ route('admin.catalogue.fieldReviews.dismiss', $review->id) }}">
                        @csrf
                        <button class="btn btn-sm btn-outline-secondary" type="submit">Keep as it is</button>
                    </form>
                </div>
            </div>
        </div>
    @empty
        <p class="text-secondary">Nothing is waiting. Run <code>php artisan catalogue:reclassify</code> to look for programmes that can be placed more exactly.</p>
    @endforelse

    {{ $reviews->links() }}

@endsection
