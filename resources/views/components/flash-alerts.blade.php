{{--
    Session flashes plus any validation summary, in one place for every layout.

    The pt-3 lives here, on a wrapper that only exists when there is
    something to show, rather than on the <div class="container"> callers
    wrap this in. That div used to carry its own pt-3 unconditionally, so a
    page with nothing to flash still reserved 16px of empty top padding -
    invisible on an ordinary solid nav, but a visible seam on the landing
    page's absolutely-positioned overlay nav, which overlays main from its
    true top rather than being pushed down by it: the hero photo was
    starting 16px lower than the header that's supposed to sit flush on it.
--}}
@if(session('successMessage') || session('errorMessage') || $errors->any())
    <div class="pt-3">
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
                <div class="flex-grow-1">
                    {{ session('errorMessage') }}
                    @if(session('profileIncomplete'))
                        <div class="mt-2">
                            <a class="btn btn-sm btn-outline-danger" href="{{ route('applicant.profile') }}">Complete my profile</a>
                        </div>
                    @endif
                </div>
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
    </div>
@endif
