@extends('layouts.app')

@section('title', 'Institutions')

@section('content')

    <x-page-header title="Institutions"
                   subtitle="Universities, polytechnics and colleges. A scholarship can be restricted to students at some of them."
                   eyebrow="Governance" />

    @include('admin.catalogue.nav', ['active' => 'institutions'])

    <div class="card mb-4">
        <div class="card-header"><h2 class="h6 fw-semibold mb-0">Add an institution</h2></div>
        <div class="card-body">
            @include('admin.catalogue.institution-fields', ['institution' => null, 'action' => route('admin.catalogue.institutions.store'), 'prefix' => 'new', 'button' => 'Add'])
        </div>
    </div>

    <div class="table-responsive">
        <table class="table align-middle small">
            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Province</th><th>Programmes</th><th></th></tr></thead>
            <tbody>
                @foreach($institutions as $institution)
                    <tr @class(['text-secondary' => ! $institution->is_active])>
                        <td>{{ $institution->code }}</td>
                        <td>{{ $institution->name }} @unless($institution->is_active)<span class="badge text-bg-secondary">off</span>@endunless</td>
                        <td>{{ $institution->typeLabel() }}</td>
                        <td>{{ $institution->province ?? 'not recorded' }}</td>
                        <td>{{ $institution->programmes_count }}</td>
                        <td class="text-end">
                            <details>
                                <summary>Edit</summary>
                                <div class="text-start pt-2">
                                    @include('admin.catalogue.institution-fields', ['institution' => $institution, 'action' => route('admin.catalogue.institutions.update', $institution->id), 'prefix' => 'i' . $institution->id, 'button' => 'Save'])
                                </div>
                            </details>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

@endsection
