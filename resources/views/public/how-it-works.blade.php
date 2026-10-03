@extends('layouts.public')

@section('title', 'How ScholarZim works')

@section('meta_description', 'A step-by-step look at how ScholarZim helps Zimbabwean students create a profile, discover scholarships and apply.')

@section('content')

    {{--
        The landing page only introduces this process in three short lines;
        this page is where "Learn How It Works" and the nav's "How It Works"
        actually land. Same three steps, same icons, just given a page of
        their own instead of competing with the landing page's other
        sections - see the landing page's "do not reproduce the destination"
        rule.
    --}}
    <section class="py-5">
        <div class="container">
            <div class="text-center mb-5">
                <p class="sz-eyebrow mb-2">How It Works</p>
                <h1 class="h3 fw-bold mb-2">How ScholarZim Works</h1>
                <p class="text-secondary mb-0">A simple process to help you find and apply for the right opportunities.</p>
            </div>

            <div class="row g-4 align-items-start sz-steps-row">
                @foreach([
                    ['person', 'Create Your Profile', 'Add your academic and personal information.'],
                    ['search', 'Discover Opportunities', 'Browse scholarships that match your eligibility.'],
                    ['file-text', 'Apply & Track', 'Submit applications and monitor their progress.'],
                ] as $index => [$icon, $title, $copy])
                    <div class="col-lg-4 text-center position-relative">
                        @if($index > 0)
                            <span class="sz-step-arrow d-none d-lg-block text-secondary" aria-hidden="true">&rarr;</span>
                        @endif
                        <div class="sz-eyebrow mb-2">{{ sprintf('%02d', $index + 1) }}</div>
                        <span class="sz-stat-icon bg-primary-subtle text-primary rounded-circle d-inline-flex align-items-center justify-content-center mb-3">
                            <x-icon :name="$icon" :size="22" />
                        </span>
                        <h2 class="h6 fw-semibold mb-1">{{ $title }}</h2>
                        <p class="small text-secondary mb-0">{{ $copy }}</p>
                    </div>
                @endforeach
            </div>

            <div class="text-center mt-5 pt-4 border-top">
                <p class="text-secondary mb-3">Ready to get started?</p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a class="btn btn-primary" href="{{ route('register') }}">Get Started</a>
                    <a class="btn btn-outline-primary" href="{{ route('scholarships.index') }}">Find Scholarships</a>
                </div>
            </div>
        </div>
    </section>

@endsection
