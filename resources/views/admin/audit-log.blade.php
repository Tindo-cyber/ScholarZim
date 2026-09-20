@extends('layouts.app')

@section('title', 'Audit log')

@section('content')

    <x-page-header title="Audit log"
                   :subtitle="number_format($entries->total()) . ' recorded event(s).'"
                   eyebrow="Administration" />

    <form method="GET" action="{{ route('admin.audit') }}" class="card mb-4">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="actor">Actor</label>
                <input type="search" class="form-control" id="actor" name="actor"
                       value="{{ $filters['actor'] ?? '' }}" placeholder="Email address">
            </div>
            <div class="col-md-4">
                <label class="form-label" for="action">Action</label>
                <select class="form-select" id="action" name="action">
                    <option value="">All actions</option>
                    @foreach($actions as $action)
                        <option value="{{ $action }}" @selected(($filters['action'] ?? '') === $action)>
                            {{ \App\Support\AuditAction::displayLabel($action) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label" for="entity_type">Entity</label>
                <input type="search" class="form-control" id="entity_type" name="entity_type"
                       value="{{ $filters['entity_type'] ?? '' }}" placeholder="USER">
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-primary" type="submit">Filter</button>
            </div>
        </div>
    </form>

    <div class="card">
        <x-data-table :columns="['When', 'Actor', 'Action', 'Entity', 'Details']"
                      :empty="$entries->isEmpty()"
                      empty-title="No audit entries match those filters"
                      empty-icon="shield">
            @foreach($entries as $entry)
                <tr>
                    <x-data-table.cell label="When" class="text-secondary small text-nowrap">
                        {{ $entry->created_at?->format('d M Y H:i') }}
                    </x-data-table.cell>
                    <x-data-table.cell label="Actor" class="small">{{ $entry->actor_email }}</x-data-table.cell>
                    <x-data-table.cell label="Action">
                        <x-status-badge :label="\App\Support\AuditAction::displayLabel($entry->action)"
                                        :tone="\App\Support\AuditAction::badgeTone($entry->action)" />
                    </x-data-table.cell>
                    <x-data-table.cell label="Entity" class="small text-secondary">
                        {{ $entry->entity_type }}@if($entry->entity_id) #{{ $entry->entity_id }}@endif
                    </x-data-table.cell>
                    <x-data-table.cell label="Details" class="small">{{ $entry->details }}</x-data-table.cell>
                </tr>
            @endforeach
        </x-data-table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>

@endsection
