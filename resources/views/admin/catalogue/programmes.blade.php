@extends('layouts.app')

@section('title', 'Programmes')

@section('content')

    <x-page-header title="Programmes" :subtitle="$programmes->total() . ' shown'" eyebrow="Governance">
        <x-slot:actions>
            <a class="btn btn-primary" href="{{ route('admin.catalogue.programmes.create') }}">Add a programme</a>
        </x-slot:actions>
    </x-page-header>

    @include('admin.catalogue.nav', ['active' => 'programmes'])

    <form method="GET" class="row g-2 mb-3" role="search">
        <div class="col-md-4">
            <label class="visually-hidden" for="q">Search</label>
            <input class="form-control" id="q" name="q" value="{{ request('q') }}" placeholder="Name or synonym">
        </div>
        <div class="col-md-2">
            <label class="visually-hidden" for="level">Level</label>
            <select class="form-select" id="level" name="level">
                <option value="">Any level</option>
                @foreach($levels as $level)
                    <option value="{{ $level }}" @selected(request('level') === $level)>{{ \App\Support\EducationLevel::label($level) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="visually-hidden" for="field">Field</label>
            <select class="form-select" id="field" name="field">
                <option value="">Any field</option>
                @foreach($fields->groupBy(fn ($f) => $f->parent->name) as $broad => $narrow)
                    <optgroup label="{{ $broad }}">
                        @foreach($narrow as $field)
                            <option value="{{ $field->id }}" @selected((int) request('field') === $field->id)>{{ $field->name }}</option>
                        @endforeach
                    </optgroup>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="visually-hidden" for="status">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="">In the catalogue</option>
                <option value="inactive" @selected(request('status') === 'inactive')>Switched off</option>
                <option value="pending" @selected(request('status') === 'pending')>Waiting for approval</option>
            </select>
        </div>
        <div class="col-md-1 d-grid"><button class="btn btn-outline-secondary" type="submit">Filter</button></div>
    </form>

    <div class="table-responsive">
        <table class="table align-middle">
            <thead>
                <tr><th>Programme</th><th>Level</th><th>Field</th><th>Offered at</th><th>Synonyms</th><th></th></tr>
            </thead>
            <tbody>
                @forelse($programmes as $programme)
                    <tr>
                        <td>
                            {{ $programme->name }}
                            @unless($programme->is_active)<span class="badge text-bg-secondary ms-1">off</span>@endunless
                            @if($programme->isPending())<span class="badge text-bg-warning ms-1">pending</span>@endif
                        </td>
                        <td>{{ $programme->levelLabel() }}</td>
                        <td class="small">{{ $programme->field->name }}</td>
                        <td class="small">{{ $programme->institutions->count() ?: 'not recorded' }}</td>
                        <td class="small">{{ $programme->synonyms_count }}</td>
                        <td class="text-end"><a href="{{ route('admin.catalogue.programmes.edit', $programme->id) }}">Edit</a></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="text-secondary">Nothing matches.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $programmes->links() }}

@endsection
