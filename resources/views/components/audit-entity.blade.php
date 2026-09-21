@props(['type', 'id' => null])

@php
    /**
     * What an audit row was about, in words rather than in the token the column
     * stores.
     *
     * The audit log and the dashboard's activity list both printed entity_type
     * raw, so a governance screen read "OPPORTUNITY #4" - the storage name of a
     * thing this application calls a scholarship everywhere else it is shown.
     *
     * Only the two tokens whose stored name differs from the word the product
     * uses are mapped. Everything else falls through to the same sentence-case
     * treatment AuditAction::displayLabel() gives an action, so an entity type
     * added later reads sensibly here without this map being remembered.
     */
    $words = [
        'OPPORTUNITY' => 'Scholarship',
        'APPLICANT_PROFILE' => 'Applicant profile',
    ];

    $token = strtoupper(trim((string) $type));
    $label = $token === ''
        ? '-'
        : ($words[$token] ?? ucfirst(strtolower(str_replace('_', ' ', $token))));
@endphp

<span {{ $attributes }}>{{ $label }}@if($id) <span class="sz-tabular">#{{ $id }}</span>@endif</span>
