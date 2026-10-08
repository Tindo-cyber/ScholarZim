@extends('layouts.app')

@section('title', 'Waiting for approval')

@section('content')

    <x-page-header title="Waiting for approval"
                   subtitle="Programmes a student or provider typed because theirs was not listed."
                   eyebrow="Governance" />

    @include('admin.catalogue.nav', ['active' => 'pending'])

    {{--
        Approve adds it to the catalogue as typed. Merge says it was really an existing programme: whoever
        chose it is moved across, and the typed name becomes a synonym so the next person finds the right one.
        Reject is for nonsense; whoever chose it just loses that choice.
    --}}
    @forelse($pending as $programme)
        <div class="card mb-3">
            <div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                    <div>
                        <div class="fw-semibold">{{ $programme->name }}</div>
                        <div class="small text-secondary">
                            {{ $programme->levelLabel() }} &middot; {{ $programme->field->name }}
                            &middot; suggested by {{ $programme->suggester?->full_name ?? 'someone whose account is gone' }}
                            on {{ $programme->created_at->format('d M Y') }}
                        </div>
                    </div>
                    <div class="d-flex gap-2 align-items-start">
                        <form method="POST" action="{{ route('admin.catalogue.pending.approve', $programme->id) }}">
                            @csrf
                            <button class="btn btn-sm btn-success" type="submit">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('admin.catalogue.pending.reject', $programme->id) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger" type="submit">Reject</button>
                        </form>
                    </div>
                </div>

                <form method="POST" action="{{ route('admin.catalogue.pending.merge', $programme->id) }}" class="row g-2">
                    @csrf
                    <div class="col-md-9">
                        <label class="visually-hidden" for="into-{{ $programme->id }}">Merge into</label>
                        <select class="form-select form-select-sm" id="into-{{ $programme->id }}" name="into" required>
                            <option value="">It is really one of these - merge into...</option>
                            @foreach($targets->get($programme->education_level, collect()) as $target)
                                <option value="{{ $target->id }}">{{ $target->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-3 d-grid"><button class="btn btn-sm btn-outline-secondary" type="submit">Merge</button></div>
                </form>
            </div>
        </div>
    @empty
        <x-empty-state title="Nothing is waiting" message="When someone types a programme that is not in the catalogue it will appear here." icon="check-circle" />
    @endforelse

@endsection
