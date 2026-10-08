@extends('layouts.app')

@section('title', 'Old values')

@section('content')

    <x-page-header title="Old values"
                   subtitle="Fields of study typed before the catalogue existed. Say what each one means, once."
                   eyebrow="Governance" />

    @include('admin.catalogue.nav', ['active' => 'old'])

    {{--
        Listings with an old field of study get the matching catalogue fields as "Open to" rows (marked as
        made by this migration, and never replacing anything a provider chose). Students are not moved: an old
        field is a field, not a programme, so they are asked to choose their programme instead.
    --}}
    <div class="card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-semibold">{{ $migratable }} {{ \Illuminate\Support\Str::plural('listing', $migratable) }} can be moved onto the catalogue now.</div>
                <div class="small text-secondary">
                    The old field stays where it is. This can be undone with <code>php artisan catalogue:migrate-fields --undo</code>.
                </div>
            </div>
            @if($migratable > 0)
                <form method="POST" action="{{ route('admin.catalogue.oldValues.migrate') }}">
                    @csrf
                    <button class="btn btn-primary" type="submit">Move them now</button>
                </form>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header"><h2 class="h6 fw-semibold mb-0">Values nothing in the catalogue knows</h2></div>
        <div class="card-body">
            @forelse($unmapped as $row)
                <form method="POST" action="{{ route('admin.catalogue.oldValues.map') }}" class="row g-2 align-items-center border-bottom py-2">
                    @csrf
                    <input type="hidden" name="value" value="{{ $row['example'] }}">
                    <div class="col-md-4">
                        <div class="fw-semibold">{{ $row['example'] }}</div>
                        <div class="small text-secondary">{{ $row['listings'] }} {{ \Illuminate\Support\Str::plural('listing', $row['listings']) }}, {{ $row['students'] }} {{ \Illuminate\Support\Str::plural('student', $row['students']) }}</div>
                    </div>
                    <div class="col-md-6">
                        <label class="visually-hidden" for="map-{{ $loop->index }}">Means</label>
                        <select class="form-select form-select-sm" id="map-{{ $loop->index }}" name="field_id" required>
                            <option value="">It means...</option>
                            @foreach($fields->groupBy(fn ($f) => $f->parent->label()) as $broad => $narrow)
                                <optgroup label="{{ $broad }}">
                                    @foreach($narrow as $field)
                                        <option value="{{ $field->id }}">{{ $field->label() }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-2 d-grid"><button class="btn btn-sm btn-outline-primary" type="submit">Save</button></div>
                </form>
            @empty
                <p class="mb-0 text-secondary">Every old value is understood.</p>
            @endforelse
            @error('value')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
            @error('field_id')<div class="text-danger small mt-2">{{ $message }}</div>@enderror
        </div>
    </div>

    @if($aliases->isNotEmpty())
        <div class="card">
            <div class="card-header"><h2 class="h6 fw-semibold mb-0">Meanings you have set</h2></div>
            <ul class="list-group list-group-flush small">
                @foreach($aliases as $alias)
                    <li class="list-group-item d-flex justify-content-between align-items-center">
                        <span>"{{ $alias->original_value }}" means <strong>{{ $alias->field->label() }}</strong></span>
                        <form method="POST" action="{{ route('admin.catalogue.oldValues.delete', $alias->id) }}">
                            @csrf
                            <button class="btn btn-sm btn-outline-danger" type="submit">Remove</button>
                        </form>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

@endsection
