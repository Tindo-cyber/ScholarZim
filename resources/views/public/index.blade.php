@extends('layouts.public')

@section('title', 'Scholarships for Zimbabwean students')

@section('content')

    <section class="sz-hero py-5">
        <div class="container py-lg-4">
            <div class="row align-items-center g-5">
                <div class="col-lg-6">
                    <span class="badge rounded-pill bg-primary-subtle text-primary mb-3">ScholarFit matching</span>

                    <h1 class="display-5 fw-bold mb-3">
                        Find scholarships you actually <span class="text-primary">qualify for</span>.
                    </h1>

                    <p class="fs-5 text-secondary mb-4">
                        ScholarZim scores every listing against your academic profile and tells you exactly
                        which criteria you meet — and which ones to fix before you apply.
                    </p>

                    <form action="{{ route('scholarships.index') }}" method="GET" class="mb-4">
                        <div class="input-group input-group-lg shadow-sm">
                            <input type="search" class="form-control border-end-0" name="keyword"
                                   placeholder="Try &quot;engineering&quot; or &quot;Masters&quot;" aria-label="Search scholarships">
                            <button class="btn btn-primary px-4" type="submit">Search</button>
                        </div>
                    </form>

                    <div class="d-flex flex-wrap gap-2">
                        @foreach(array_slice($fields, 0, 5) as $field)
                            <a class="btn btn-sm btn-outline-secondary rounded-pill"
                               href="{{ route('scholarships.index', ['field_of_study' => $field]) }}">{{ $field }}</a>
                        @endforeach
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="row g-3">
                        <div class="col-6">
                            <x-stat-card label="Open scholarships" :value="number_format($stats['activeScholarships'])"
                                         icon="stars" tone="primary" />
                        </div>
                        <div class="col-6">
                            <x-stat-card label="Closing this month" :value="number_format($stats['closingSoon'])"
                                         icon="clock-history" tone="danger" />
                        </div>
                        <div class="col-6">
                            <x-stat-card label="Students" :value="number_format($stats['students'])"
                                         icon="people" tone="info" />
                        </div>
                        <div class="col-6">
                            <x-stat-card label="Awards made" :value="number_format($stats['awardsMade'])"
                                         icon="check-circle" tone="success" />
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5" id="featured">
        <div class="container">
            <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-4">
                <div>
                    <h2 class="h3 fw-bold mb-1">Closing soon</h2>
                    <p class="text-secondary mb-0">Live listings, ordered by the nearest deadline.</p>
                </div>
                <a class="btn btn-outline-primary" href="{{ route('scholarships.index') }}">Browse all</a>
            </div>

            @if($featured->isEmpty())
                <x-empty-state title="No open scholarships right now"
                               message="New listings are published as soon as an administrator approves them. Check back shortly."
                               icon="stars" />
            @else
                <div class="row g-3 g-lg-4">
                    @foreach($featured as $opportunity)
                        <div class="col-md-6 col-lg-4">
                            <x-scholarship-card :opportunity="$opportunity" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="py-5 bg-body-secondary" id="how-it-works">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="h3 fw-bold mb-2">How ScholarZim works</h2>
                <p class="text-secondary mb-0">Three steps, and the score explains itself at every stage.</p>
            </div>

            <div class="row g-4">
                @foreach([
                    ['Build your profile', 'Add your education level, field of study, results, and certificate once. Everything else keys off it.'],
                    ['Get scored matches', 'ScholarFit rates each listing out of 100 across six dimensions and shows the criteria you meet.'],
                    ['Apply and track', 'Submit through a guided wizard, then follow every status change from one dashboard.'],
                ] as $index => [$title, $copy])
                    <div class="col-md-4">
                        <div class="card h-100 border-0 bg-body">
                            <div class="card-body">
                                <span class="sz-step-number mb-3">{{ $index + 1 }}</span>
                                <h3 class="h5 fw-semibold">{{ $title }}</h3>
                                <p class="text-secondary mb-0">{{ $copy }}</p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-5">
        <div class="container">
            <div class="card border-0 bg-primary text-white overflow-hidden">
                <div class="card-body p-4 p-lg-5">
                    <div class="row align-items-center g-4">
                        <div class="col-lg-8">
                            <h2 class="h3 fw-bold mb-2">Awarding scholarships?</h2>
                            <p class="mb-0 opacity-75">
                                Publish your programme to verified Zimbabwean students, review applications in one
                                inbox, and export decisions when you are done. Listings go live once our team
                                verifies your organisation.
                            </p>
                        </div>
                        <div class="col-lg-4 text-lg-end">
                            <a class="btn btn-light btn-lg" href="{{ route('register.provider') }}">Register as a provider</a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
