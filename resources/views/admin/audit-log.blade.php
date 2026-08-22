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
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th scope="col">When</th>
                        <th scope="col">Actor</th>
                        <th scope="col">Action</th>
                        <th scope="col">Entity</th>
                        <th scope="col">Details</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($entries as $entry)
                        <tr>
                            <td class="text-secondary small text-nowrap">
                                {{ $entry->created_at?->format('d M Y H:i') }}
                            </td>
                            <td class="small">{{ $entry->actor_email }}</td>
                            <td>
                                <x-status-badge :label="\App\Support\AuditAction::displayLabel($entry->action)"
                                                :tone="\App\Support\AuditAction::badgeTone($entry->action)" />
                            </td>
                            <td class="small text-secondary">
                                {{ $entry->entity_type }}@if($entry->entity_id) #{{ $entry->entity_id }}@endif
                            </td>
                            <td class="small">{{ $entry->details }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5">
                                <x-empty-state title="No audit entries match those filters" icon="shield" />
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>

@endsection
