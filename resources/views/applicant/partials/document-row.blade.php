{{--
    One document on the profile: its name, whether it is required and on file, the file if there is one, and an upload
    box. The item comes from ApplicantProfile::documentChecklist() - the same list the apply wizard, the submission
    check and the provider's review page use - so what this row says is what the application will enforce.

    Deliberately no explanatory paragraph: the label says what it is, the badge says whether it is needed, and the
    section above says how to upload.
--}}
@props(['item'])

@php
    $type = $item['type'];
    $label = $item['label'];
    $filename = $item['filename'];
@endphp

<div id="document-{{ $type }}">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-1">
        <span class="fw-semibold small">{{ $label }}</span>
        @if($item['present'])
            <x-status-badge label="Uploaded" tone="success" icon="check" />
        @elseif($item['required'])
            <x-status-badge label="Required" tone="danger" />
        @else
            <x-status-badge label="Optional" tone="secondary" />
        @endif
    </div>

    @if($item['present'])
        <p class="small mb-2">
            <a class="text-decoration-none d-inline-flex align-items-center gap-1"
               href="{{ route('files.myDocument', $type) }}" target="_blank" rel="noopener">
                <x-icon name="eye" :size="14" />{{ $filename ?: $label }}
            </a>
            @if($item['uploaded_at'])
                <span class="text-secondary d-block">Uploaded {{ $item['uploaded_at']->diffForHumans() }}</span>
            @endif
        </p>
    @endif

    <form method="POST" action="{{ route('applicant.profile.documents', $type) }}"
          enctype="multipart/form-data" class="d-flex gap-2">
        @csrf
        <input class="form-control form-control-sm @error('document') is-invalid @enderror" type="file" name="document"
               accept=".pdf,.jpg,.jpeg,.png,.doc,.docx" required aria-label="Upload {{ $label }}">
        <x-submit-button :label="$item['present'] ? 'Replace' : 'Upload'" busy-label="Uploading..."
                         tone="outline-primary" size="sm" class="flex-shrink-0" />
    </form>
</div>
