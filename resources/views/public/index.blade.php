@extends('layouts.public')

@section('title', 'Scholarships for Zimbabwean students')

@section('meta_description', 'ScholarZim connects Zimbabwean students with scholarships and educational opportunities that match their academic profile and goals.')

@section('body_class', 'sz-landing')

@section('content')

    {{--
        Structured after the agreed visual reference: a full-bleed photo
        hero with an overlay nav, three short feature cards, a ScholarFit
        introduction, a student/provider pair, and a photo-backed closing
        CTA - in the existing ScholarZim palette, with a gold accent used
        the way the reference uses it (the hero's second headline line and
        the overlay nav's active indicator) rather than gold replacing the
        site's blue everywhere - Get Started and Sign in keep the same
        colours they use on every other page, including the auth pages
        they lead to. Built entirely from local crops of the reference
        image itself (see public/assets/landing/) rather than any
        externally-hosted photo.

        This page deliberately stops at an introduction. The full
        scholarship catalogue and the full "how it works" process each
        have their own page, and this page links to them rather than
        reproducing them - see the hero and panel CTAs below.
    --}}
    {{--
        Three background layers: a top-edge vignette so the area behind
        the overlay nav is reliably dark no matter where the person or the
        sky falls at a given viewport (see the long comment on
        .sz-landing .sz-public-nav in scholarzim.css), the existing
        left-to-right gradient that keeps the headline readable, and the
        photo itself, covering the full section - see the long comment on
        .sz-hero-photo for why the crop, not just background-size: cover,
        is what actually keeps it clear of the text.
    --}}
    <section class="sz-hero-photo" style="background-image: linear-gradient(to bottom, rgba(4,13,30,.72) 0%, rgba(4,13,30,0) 150px), linear-gradient(90deg, rgba(4,13,30,.95) 0%, rgba(4,13,30,.86) 42%, rgba(4,13,30,.35) 68%, rgba(4,13,30,.1) 100%), url('{{ asset('assets/landing/hero-student.jpg') }}')">
        <div class="container py-5">
            <div class="row">
                {{--
                    col-12 col-lg-5, not col-lg-5 alone: without a base
                    column class, Bootstrap gives this element no width
                    rule at all below 992px, so it was filling the row's
                    full width - right up against the photo - at exactly
                    the width this screen most needs it narrow. col-lg-5
                    (not 6) is close to the reference's own ~44% text
                    column.

                    The inline max-width is doing the real work below lg,
                    though: col-12 still means "the whole row," and the
                    photo behind the row is full-bleed at every width (see
                    the long comment on .sz-hero-photo), so an unconstrained
                    full-width column would stretch the headline out over
                    wherever her face lands at that viewport. Capping the
                    measure keeps the text block the same comfortable
                    reading width it already is at lg and up, rather than
                    growing to fill space the photo also needs.
                --}}
                <div class="col-12 col-lg-5 py-lg-5" style="max-width: 29rem;">
                    <h1 class="display-5 fw-bold mb-3 text-white">
                        Discover Opportunities.<br>
                        <span class="sz-hero-accent">Build Your Future.</span>
                    </h1>

                    <p class="fs-5 mb-4 sz-hero-copy">
                        ScholarZim connects Zimbabwean students with scholarships and educational
                        opportunities that match your academic profile and goals.
                    </p>

                    <div class="d-flex flex-wrap gap-2 mb-4">
                        <a class="btn btn-primary btn-lg" href="{{ route('scholarships.index') }}">
                            <x-icon name="search" :size="18" /> Find Scholarships
                        </a>
                        <a class="btn btn-outline-light btn-lg" href="{{ route('how-it-works') }}">
                            Learn How It Works
                        </a>
                    </div>

                    <p class="small sz-hero-copy mb-0 d-flex align-items-center gap-2">
                        <x-icon name="check-circle" :size="16" />
                        One platform for scholarships, applications and opportunity discovery.
                    </p>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-body-secondary">
        <div class="container">
            <div class="row g-4">
                @foreach([
                    ['search', 'Find', 'Discover scholarship opportunities in one place.', 'primary'],
                    ['people', 'Match', 'ScholarFit checks your profile against scholarship requirements.', 'success'],
                    ['file-text', 'Apply', 'Manage scholarship applications from one platform.', 'warning'],
                ] as [$icon, $title, $copy, $tone])
                    {{--
                        col-sm-6 col-lg-4, not col-md-4: a browser window
                        resized to exactly 768px (the md breakpoint) often
                        has an effective content width a few pixels under
                        it once chrome/scrollbar are subtracted, which
                        leaves col-md-4 stuck at one column with the rest
                        of the row empty.
                    --}}
                    <div class="col-sm-6 col-lg-4">
                        <div class="card h-100 border-0 shadow-sm">
                            <div class="card-body d-flex flex-column">
                                <span class="sz-stat-icon bg-{{ $tone }}-subtle text-{{ $tone }} rounded-3 d-inline-flex align-items-center justify-content-center mb-3">
                                    <x-icon :name="$icon" :size="20" />
                                </span>
                                <h2 class="h6 fw-semibold">{{ $title }}</h2>
                                <p class="small text-secondary mb-0">{{ $copy }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <p class="sz-eyebrow mb-2">ScholarFit</p>
                    <h2 class="h3 fw-bold mb-3">ScholarFit helps you find opportunities that fit your profile.</h2>
                    <p class="text-secondary mb-4">
                        ScholarFit checks scholarship requirements against your academic and personal
                        information and shows you which opportunities you are eligible for.
                    </p>

                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <x-icon name="check-circle" :size="20" class="text-success flex-shrink-0 mt-1" />
                                <div>
                                    <p class="fw-semibold mb-0">Eligible</p>
                                    <p class="small text-secondary mb-0">Your profile meets the requirements.</p>
                                </div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="d-flex gap-2">
                                <x-icon name="x-circle" :size="20" class="text-danger flex-shrink-0 mt-1" />
                                <div>
                                    <p class="fw-semibold mb-0">Not Eligible</p>
                                    <p class="small text-secondary mb-0">See which requirements are not met.</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <img src="{{ asset('assets/landing/scholarfit-student.jpg') }}"
                         alt="A student checking scholarship eligibility on a laptop"
                         class="img-fluid rounded-4 w-100 sz-panel-photo" loading="lazy">
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-body-secondary">
        <div class="container">
            <div class="row g-4">
                <div class="col-lg-6">
                    <div class="card h-100 border-0 overflow-hidden bg-warning-subtle">
                        <div class="row g-0 h-100">
                            <div class="col-12 col-sm-7 col-md-6">
                                <div class="card-body p-4 p-lg-5 h-100 d-flex flex-column">
                                    <p class="sz-eyebrow mb-2">For students</p>
                                    <h2 class="h4 fw-bold mb-3">Your next opportunity could start here.</h2>
                                    <p class="text-secondary mb-4">
                                        Create your profile, discover scholarships, check your eligibility with
                                        ScholarFit, submit applications and track your progress.
                                    </p>
                                    <a class="btn btn-primary mt-auto align-self-start" href="{{ route('register') }}">Get Started</a>
                                </div>
                            </div>
                            <div class="col-12 col-sm-5 col-md-6">
                                <img src="{{ asset('assets/landing/student-section.jpg') }}"
                                     alt="A student holding a stack of books"
                                     class="sz-panel-photo-lg" loading="lazy">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="card h-100 border-0 overflow-hidden bg-primary-subtle">
                        <div class="row g-0 h-100">
                            <div class="col-12 col-sm-7 col-md-6">
                                <div class="card-body p-4 p-lg-5 h-100 d-flex flex-column">
                                    <p class="sz-eyebrow mb-2">For providers</p>
                                    <h2 class="h4 fw-bold mb-3">Reach students through a structured scholarship platform.</h2>
                                    <p class="text-secondary mb-4">
                                        Publish scholarship opportunities, review applications, and manage your
                                        organisation's listings in one place.
                                    </p>
                                    <a class="btn btn-outline-primary bg-body mt-auto align-self-start" href="{{ route('register.provider') }}">Learn More</a>
                                </div>
                            </div>
                            <div class="col-12 col-sm-5 col-md-6">
                                <img src="{{ asset('assets/landing/provider-section.jpg') }}"
                                     alt="A provider reviewing applications on a laptop"
                                     class="sz-panel-photo-lg" loading="lazy">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{--
        This crop (unlike the first attempt) is a clean margin of the
        reference's own CTA photo with no heading, button or other UI baked
        into it - see the cropping notes in the landing assets. The overlay
        only needs to carry text contrast now, not also hide a second,
        conflicting headline underneath this page's real one.
    --}}
    <section class="sz-cta-photo" style="background-image: linear-gradient(rgba(4,13,30,.62), rgba(4,13,30,.62)), url('{{ asset('assets/landing/cta-background.jpg') }}')">
        <div class="container text-center py-5 text-white">
            <h2 class="h3 fw-bold mb-2">Ready to discover your next opportunity?</h2>
            <p class="mb-4">Explore scholarships, check your eligibility and take the next step towards your future.</p>
            <a class="btn btn-primary btn-lg" href="{{ route('scholarships.index') }}">
                <x-icon name="search" :size="18" /> Explore Scholarships
            </a>
        </div>
    </section>

@endsection
