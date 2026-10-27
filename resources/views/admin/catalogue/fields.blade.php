@extends('layouts.app')

@section('title', 'Fields')

@section('content')

    <x-page-header title="Fields of study"
                   subtitle="The ISCED-F 2013 classification: broad, narrow and detailed fields. Providers can only target fields marked as targetable."
                   eyebrow="Governance" />

    @include('admin.catalogue.nav', ['active' => 'fields'])

    <div class="card mb-4">
        <div class="card-header"><h2 class="h6 fw-semibold mb-0">Add a field</h2></div>
        <div class="card-body">
            <form method="POST" action="{{ route('admin.catalogue.fields.store') }}" class="row g-2" novalidate>
                @csrf
                <div class="col-md-2">
                    <label class="form-label small" for="new-code">Code</label>
                    <input class="form-control @error('code') is-invalid @enderror" id="new-code" name="code" value="{{ old('code') }}" maxlength="4">
                    @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-5">
                    <label class="form-label small" for="new-name">Name</label>
                    <input class="form-control @error('name') is-invalid @enderror" id="new-name" name="name" value="{{ old('name') }}" maxlength="150">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label small" for="new-parent">Inside (leave empty for a broad field)</label>
                    <select class="form-select @error('parent_id') is-invalid @enderror" id="new-parent" name="parent_id">
                        <option value="">- broad field -</option>
                        @foreach($parents as $p)
                            <option value="{{ $p->id }}" @selected((int) old('parent_id') === $p->id)>{{ $p->level === 'narrow' ? '— ' : '' }}{{ $p->code }} {{ $p->name }}</option>
                        @endforeach
                    </select>
                    @error('parent_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Add</button></div>
            </form>
        </div>
    </div>

    @foreach($broad as $b)
        <div class="card mb-3">
            <div class="card-header">
                <details>
                    <summary class="fw-semibold">{{ $b->code }} &middot; {{ $b->name }}</summary>
                    <form method="POST" action="{{ route('admin.catalogue.fields.update', $b->id) }}" class="row g-2 mt-1" novalidate>
                        @csrf
                        <input type="hidden" name="code" value="{{ $b->code }}">
                        <div class="col-md-9"><input class="form-control form-control-sm" name="name" value="{{ $b->name }}" maxlength="150" aria-label="Name of {{ $b->name }}"></div>
                        <div class="col-md-3"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Rename</button></div>
                    </form>
                </details>
            </div>
            <ul class="list-group list-group-flush small">
                @foreach($below->get($b->code, collect()) as $n)
                    <li class="list-group-item d-flex justify-content-between gap-2 @if(! $n->is_active) text-secondary @endif">
                        <span style="padding-left: {{ $n->level === 'detailed' ? '1.5rem' : '0' }}">
                            {{ $n->code }} &middot; {{ $n->name }}
                            @if($n->kind !== 'official')<span class="badge text-bg-light border">{{ str_replace('_', ' ', $n->kind) }}</span>@endif
                            @if(! $n->is_selectable)<span class="badge text-bg-light border">heading only</span>@endif
                            @if(! $n->is_active)<span class="badge text-bg-secondary">switched off</span>@endif
                        </span>
                        <span class="text-secondary text-nowrap">{{ $n->programmes_count }} {{ \Illuminate\Support\Str::plural('programme', $n->programmes_count) }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endforeach

@endsection
