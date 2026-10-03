@extends('layouts.public')

@section('title', 'Scholarships for Zimbabwean students')

@section('meta_description', 'A free platform for Zimbabwean students to discover scholarship opportunities, check eligibility, and manage applications in one place.')

@section('content')

    {{--
        The landing page is a concise introduction, not the platform itself -
        it answers what ScholarZim is, who it is for, what a student can do
        with it, and where to go next, then hands off to dedicated pages
        (the browse catalogue, the ScholarFit explanation, provider
        registration) rather than trying to contain all of them. Those pages
        already exist and already do this properly; repeating their content
        here only meant updating it twice.
    --}}
    <section class="sz-hero py-5">
        <div class="container py-lg-4">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <p class="sz-eyebrow mb-2">ScholarZim</p>

                    <h1 class="h1 fw-bold mb-3">Find scholarships that fit your profile.</h1>

                    <p class="fs-5 text-secondary mb-4">
                        A free platform for Zimbabwean students to discover scholarship opportunities,
                        check eligibility, and manage applications in one place.
                    </p>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary btn-lg" href="{{ route('scholarships.index') }}">Find scholarships</a>
                        <a class="btn btn-outline-secondary btn-lg" href="{{ route('scholarfit') }}">How ScholarFit works</a>
                        <a class="btn btn-outline-secondary btn-lg" href="{{ route('register.provider') }}">For providers</a>
                    </div>
                </div>

                <div class="col-lg-5">
                    {{--
                        Counts, not claims: every figure is a live count from
                        PlatformStatsService, so an empty platform says zero
                        rather than inventing traction.
                    --}}
                    <div class="card">
                        <div class="card-body">
                            <h2 class="sz-eyebrow mb-3">ScholarZim at a glance</h2>
                            <div class="row g-3 text-center text-sm-start">
                                @foreach([
                                    ['Open scholarships', $stats['activeScholarships']],
                                    ['Closing this month', $stats['closingSoon']],
                                    ['Registered students', $stats['students']],
                                    ['Scholarships awarded', $stats['awardsMade']],
                                ] as [$label, $value])
                                    <div class="col-6">
                                        <div class="fs-3 fw-bold lh-1 sz-tabular">{{ number_format($value) }}</div>
                                        <div class="small fw-semibold mt-1">{{ $label }}</div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-body-secondary">
        <div class="container">
            <div class="mb-4">
                <h2 class="h3 fw-bold mb-2">ScholarZim brings the scholarship process together</h2>
                <p class="text-secondary mb-0">
                    Instead of searching across different websites and trying to determine whether you
                    qualify, ScholarZim gives students one place to discover opportunities, understand
                    eligibility, apply, and track their applications.
                </p>
            </div>

            {{--
                col-sm-6, not col-md-6: a browser chrome/scrollbar eats a few
                pixels of an outer window resized to exactly 768px, so a
                window at that size - and some real tablets - land just
                under the md breakpoint and get one column with half the
                screen empty instead of the two these cards are meant to
                fill. Switching to sm (576px) gives a clean two-up well
                before that boundary, with no effect above lg.
            --}}
            <div class="row g-4">
                @foreach([
                    ['search', 'Discover', 'Find scholarship opportunities based on your education level and field of study.'],
                    ['shield', 'Check', 'See how your qualifications compare with scholarship requirements.'],
                    ['file-text', 'Apply', 'Submit scholarship applications and keep track of their status and deadlines.'],
                    ['stars', 'Get matched', 'ScholarFit recommends relevant opportunities based on your profile.'],
                ] as [$icon, $title, $copy])
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100 border-0 bg-body">
                            <div class="card-body">
                                <span class="sz-stat-icon bg-primary-subtle text-primary rounded-3 d-inline-flex align-items-center justify-content-center mb-3">
                                    <x-icon :name="$icon" :size="20" />
                                </span>
                                <h3 class="h6 fw-semibold">{{ $title }}</h3>
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
            <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-4">
                <h2 class="h3 fw-bold mb-0">How ScholarZim works</h2>
                <a class="btn btn-outline-secondary" href="{{ route('scholarfit') }}">Explore ScholarFit</a>
            </div>

            <div class="row g-3 g-lg-4">
                @foreach([
                    ['Create your profile', 'Add your education, subjects, results and supporting information.'],
                    ['Find opportunities', 'Browse scholarships or use ScholarFit recommendations.'],
                    ['Check eligibility', 'Understand which requirements you meet before applying.'],
                    ['Apply and track', 'Submit your application and follow its progress.'],
                ] as $index => [$title, $copy])
                    <div class="col-sm-6 col-lg-3">
                        <div class="card h-100">
                            <div class="card-body">
                                <span class="sz-eyebrow d-block mb-2">{{ sprintf('%02d', $index + 1) }}</span>
                                <h3 class="h6 fw-semibold mb-1">{{ $title }}</h3>
                                <p class="small text-secondary mb-0">{{ $copy }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-5 bg-body-secondary">
        <div class="container">
            <div class="row g-5 align-items-center">
                <div class="col-lg-6">
                    <h2 class="h3 fw-bold mb-2">Meet ScholarFit</h2>
                    <p class="text-secondary mb-3">
                        ScholarFit compares your profile with scholarship requirements to help you
                        understand which opportunities match your qualifications.
                    </p>
                    <a class="btn btn-primary" href="{{ route('scholarfit') }}">Learn how ScholarFit works</a>
                </div>

                <div class="col-lg-6">
                    <ol class="list-unstyled d-grid gap-3 mb-0">
                        @foreach([
                            'Build your profile',
                            'Check eligibility',
                            'Get recommendations',
                        ] as $index => $step)
                            <li class="card">
                                <div class="card-body d-flex align-items-center gap-3 py-3">
                                    <span class="sz-step-number flex-shrink-0">{{ $index + 1 }}</span>
                                    <span class="fw-semibold">{{ $step }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="card border-0 sz-provider-cta text-white overflow-hidden">
                <div class="card-body p-4 p-lg-5">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-8">
                            <h2 class="h3 fw-bold mb-2">Are you an organisation offering scholarships?</h2>
                            <p class="mb-0 opacity-75">
                                Publish scholarship opportunities, manage applications, and connect with
                                students through ScholarZim.
                            </p>
                        </div>
                        <div class="col-lg-4 text-lg-end">
                            <a class="btn btn-light btn-lg" href="{{ route('register.provider') }}">For providers &rarr;</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
