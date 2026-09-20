@props(['fix'])

{{-- Where this shortfall is fixed. The target comes from the DimensionResult
     that produced it, so a fix always points at the field it is about. --}}
@if(($fix['target'] ?? null) === 'profile')
    <a class="fw-semibold" href="{{ route('applicant.profile') }}#field-{{ $fix['cta'] }}">Fix this</a>
@elseif(($fix['target'] ?? null) === 'documents')
    <a class="fw-semibold" href="{{ route('applicant.profile') }}#documents">Upload it</a>
@endif
