{{-- Session flashes plus any validation summary, in one place for every layout. --}}

@if(session('successMessage'))
    <div class="alert alert-success alert-dismissible fade show d-flex gap-2" role="alert">
        <x-icon name="check-circle" />
        <div>{{ session('successMessage') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if(session('errorMessage'))
    <div class="alert alert-danger alert-dismissible fade show d-flex gap-2" role="alert">
        <x-icon name="x-circle" />
        <div>{{ session('errorMessage') }}</div>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
@endif

@if($errors->any())
    <div class="alert alert-danger" role="alert">
        <div class="fw-semibold mb-1">Please fix the following:</div>
        <ul class="mb-0 ps-3">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
