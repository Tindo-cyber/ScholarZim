@extends('layouts.app')

@section('title', 'Audit log')

@php
    $active = collect($filters)->filter(fn ($value) => filled($value));
@endphp

@section('content')

    <x-page-header title="Audit log"
                   :subtitle="number_format($entries->total()) . ' recorded event(s), newest first.'"
                   eyebrow="Governance" />

    <form method="GET" action="{{ route('admin.audit') }}" class="card mb-4" role="search">
        <div class="card-body row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="actor">Actor</label>
                <input type="search" class="form-control" id="actor" name="actor"
                       value="{{ $filters['actor'] ?? '' }}" placeholder="Email address"
                       aria-describedby="actor-hint">
                <div class="form-text" id="actor-hint">Matches any part of the address.</div>
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
                {{--
                    Still the free-text exact match the controller has always
                    performed - a <select> here would need a list of the entity
                    types actually present, and hard-coding four would quietly
                    hide a fifth the day one is added. The datalist offers the
                    ones in use as suggestions without restricting the field,
                    which is the part that was missing: the filter matches the
                    stored token exactly, and nothing on the page said so.
                --}}
                <input type="search" class="form-control" id="entity_type" name="entity_type"
                       value="{{ $filters['entity_type'] ?? '' }}" placeholder="USER"
                       list="entity-types" aria-describedby="entity-hint">
                <datalist id="entity-types">
                    <option value="USER">User</option>
                    <option value="OPPORTUNITY">Scholarship</option>
                    <option value="APPLICATION">Application</option>
                    <option value="APPLICANT_PROFILE">Applicant profile</option>
                </datalist>
                <div class="form-text" id="entity-hint">Exact match.</div>
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button class="btn btn-primary flex-grow-1" type="submit">
                    Filter
                    @if($active->isNotEmpty())
                        <span class="badge bg-white text-primary ms-1">{{ $active->count() }}</span>
                    @endif
                </button>
                @if($active->isNotEmpty())
                    <a class="btn btn-outline-secondary" href="{{ route('admin.audit') }}">Clear</a>
                @endif
            </div>
        </div>
    </form>

    <div class="card">
        <x-data-table :columns="['When', 'Actor', 'Action', 'Entity', 'Details']"
                      :hover="false"
                      :empty="$entries->isEmpty()"
                      :empty-title="$active->isNotEmpty() ? 'No audit entries match those filters' : 'Nothing has been recorded yet'"
                      :empty-message="$active->isNotEmpty()
                          ? 'Clear the filters to see the whole log. The entity filter matches the stored token exactly - USER, not User.'
                          : 'Sign-ins, decisions, and changes to any record are written here as they happen.'"
                      empty-icon="shield">
            @foreach($entries as $entry)
                <tr>
                    {{-- Absolute time first, because this is the record you cite;
                         the relative one underneath is for reading down a page. --}}
                    <x-data-table.cell label="When" class="small text-nowrap">
                        {{ $entry->created_at?->format('d M Y H:i') }}
                        <span class="d-block text-secondary">{{ $entry->created_at?->diffForHumans() }}</span>
                    </x-data-table.cell>

                    <x-data-table.cell label="Actor" class="small">
                        {{ $entry->actor_email ?: 'System' }}
                    </x-data-table.cell>

                    <x-data-table.cell label="Action">
                        <x-status-badge :label="\App\Support\AuditAction::displayLabel($entry->action)"
                                        :tone="\App\Support\AuditAction::badgeTone($entry->action)" />
                    </x-data-table.cell>

                    <x-data-table.cell label="Entity" class="small text-secondary">
                        <x-audit-entity :type="$entry->entity_type" :id="$entry->entity_id" />
                    </x-data-table.cell>

                    <x-data-table.cell label="Details" class="small">
                        {{ $entry->details ?: '-' }}
                    </x-data-table.cell>
                </tr>
            @endforeach
        </x-data-table>
    </div>

    <div class="mt-4">
        {{ $entries->links() }}
    </div>

@endsection
