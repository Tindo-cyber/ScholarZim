@extends('layouts.app')

@section('title', 'Programme catalogue')

@section('content')

    <x-page-header title="Programme catalogue"
                   subtitle="What students study and what scholarships are open to. Everyone picks from this one list."
                   eyebrow="Governance" />

    <div class="alert alert-warning small" role="note">
        <strong>The starter list is unverified.</strong>
        It was written from general knowledge, not from the institutions' own prospectuses. Check it in Excel
        (export, correct, import) before you rely on it - especially which institution offers which programme.
    </div>

    @include('admin.catalogue.nav', ['active' => 'overview'])

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <x-stat-card label="Programmes" :value="$counts['programmes']" icon="file-text" tone="primary"
                         :href="route('admin.catalogue.programmes')" />
        </div>
        <div class="col-6 col-lg-3">
            <x-stat-card label="Waiting for approval" :value="$counts['pending']" icon="hourglass-split" tone="warning"
                         :href="route('admin.catalogue.pending')" />
        </div>
        <div class="col-6 col-lg-3">
            <x-stat-card label="Fields" :value="$counts['fields']" icon="grid" tone="info"
                         :href="route('admin.catalogue.fields')" />
        </div>
        <div class="col-6 col-lg-3">
            <x-stat-card label="Institutions" :value="$counts['institutions']" icon="building" tone="success"
                         :href="route('admin.catalogue.institutions')" />
        </div>
    </div>

    @if($counts['pending'] > 0)
        <p class="small"><a href="{{ route('admin.catalogue.pending') }}">{{ $counts['pending'] }} waiting</a> for you to approve, merge or reject.</p>
    @endif

    @if($report)
        <div class="card mb-4" id="import-report">
            <div class="card-header"><h2 class="h6 fw-semibold mb-0">Last import: {{ $report['kind'] }}</h2></div>
            <div class="card-body">
                <p class="mb-2 {{ $report['problem'] ? 'text-danger' : '' }}">{{ $report['summary'] }}</p>

                @if($report['rejectedTotal'] > 0)
                    <p class="small text-secondary mb-2">
                        These rows were not imported. Fix them in your file and import it again - the rows that were
                        accepted are already in, and importing them a second time changes nothing.
                    </p>
                    <div class="table-responsive">
                        <table class="table table-sm small align-middle">
                            <thead><tr><th>Row</th><th>Why it was refused</th><th>What the row said</th></tr></thead>
                            <tbody>
                                @foreach($report['rejected'] as $row)
                                    <tr>
                                        <td class="text-nowrap">Row {{ $row['row'] }}</td>
                                        <td>{{ $row['reason'] }}</td>
                                        <td class="text-secondary">{{ collect($row['data'])->filter()->take(3)->implode(' | ') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if($report['rejectedTotal'] > count($report['rejected']))
                        <p class="small text-secondary mb-0">Showing the first {{ count($report['rejected']) }} of {{ $report['rejectedTotal'] }}.</p>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 fw-semibold mb-0">Import from a spreadsheet</h2></div>
                <div class="card-body">
                    <p class="small text-secondary">
                        A .csv (UTF-8) or .xlsx file, up to 2 MB and {{ \App\Services\Catalogue\CatalogueImporter::MAX_ROWS }} rows.
                        Importing adds and updates; it never deletes, so a file with just one institution's
                        programmes is safe. Programmes are matched on name and level.
                    </p>
                    <form method="POST" action="{{ route('admin.catalogue.import') }}" enctype="multipart/form-data" novalidate>
                        @csrf
                        <div class="mb-3">
                            <label class="form-label" for="kind">What is in the file</label>
                            <select class="form-select @error('kind') is-invalid @enderror" id="kind" name="kind">
                                @foreach($kinds as $kind)
                                    <option value="{{ $kind }}" @selected(old('kind') === $kind)>{{ ucfirst($kind) }}</option>
                                @endforeach
                            </select>
                            @error('kind')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="file">File</label>
                            <input class="form-control @error('file') is-invalid @enderror" type="file" id="file" name="file" accept=".csv,.xlsx">
                            @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <button class="btn btn-primary" type="submit">Import</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><h2 class="h6 fw-semibold mb-0">Export to correct in Excel</h2></div>
                <div class="card-body">
                    <p class="small text-secondary">The columns are exactly the ones an import reads, so what you export you can edit and import straight back.</p>
                    <ul class="list-unstyled small mb-0">
                        @foreach($kinds as $kind)
                            <li class="mb-3">
                                <div class="fw-semibold">{{ ucfirst($kind) }}</div>
                                <div class="text-secondary mb-1">Columns: {{ implode(', ', $columns[$kind]) }}</div>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.catalogue.export', [$kind, 'csv']) }}">CSV</a>
                                <a class="btn btn-sm btn-outline-secondary" href="{{ route('admin.catalogue.export', [$kind, 'xlsx']) }}">Excel</a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </div>

@endsection
