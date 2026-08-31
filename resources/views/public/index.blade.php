@extends('layouts.public')

@section('title', 'Scholarships for Zimbabwean students')

@section('meta_description', 'Search open scholarships, get a ScholarFit match score against your own profile, and apply through a guided wizard — free for Zimbabwean students.')

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

                    <div class="d-flex flex-wrap gap-2 mb-4">
                        @foreach(array_slice($fields, 0, 5) as $field)
                            <a class="btn btn-sm btn-outline-secondary rounded-pill"
                               href="{{ route('scholarships.index', ['field_of_study' => $field]) }}">{{ $field }}</a>
                        @endforeach
                        <a class="btn btn-sm btn-outline-secondary rounded-pill" href="#categories">
                            More fields &rarr;
                        </a>
                    </div>

                    <ul class="list-unstyled d-flex flex-wrap gap-3 gap-lg-4 small text-secondary mb-0">
                        <li class="d-flex align-items-center gap-2">
                            <x-icon name="check-circle" :size="16" class="text-success" />
                            Free to join and apply
                        </li>
                        <li class="d-flex align-items-center gap-2">
                            <x-icon name="shield-check" :size="16" class="text-success" />
                            Every provider is reviewed
                        </li>
                        <li class="d-flex align-items-center gap-2">
                            <x-icon name="bell" :size="16" class="text-success" />
                            Deadline reminders included
                        </li>
                    </ul>
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

    <section class="py-4">
        <div class="container">
            <div class="sz-photo-banner p-4 p-lg-5">
                <div>
                    <h2 class="h3 fw-bold mb-2">Real Zimbabwean students. Real scholarships.</h2>
                    <p class="mb-3 opacity-75" style="max-width: 32rem;">
                        Every listing on ScholarZim comes from a verified provider — no guesswork about whether
                        an opportunity is genuine.
                    </p>
                    <a class="btn btn-light" href="{{ route('scholarships.index') }}">Browse open scholarships</a>
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
                            <x-scholarship-card :opportunity="$opportunity"
                                                :saved="in_array($opportunity->opportunity_id, $savedIds, true)"
                                                :applied="in_array($opportunity->opportunity_id, $appliedIds, true)"
                                                :award="$awards[$opportunity->opportunity_id] ?? null" />
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

            <div class="row g-4 align-items-stretch">
                <div class="col-lg-4 d-none d-lg-block">
                    <div class="sz-steps-photo h-100" role="img"
                         aria-label="Stack of books, a diploma, and a graduation cap"></div>
                </div>

                <div class="col-lg-8">
                    <div class="row g-4 h-100">
                        @foreach([
                            ['person', 'Build your profile', 'Add your education level, field of study, results, and certificate once. Everything else keys off it.'],
                            ['stars', 'Get scored matches', 'ScholarFit rates each listing out of 100 across six dimensions and shows the criteria you meet.'],
                            ['file-text', 'Apply and track', 'Submit through a guided wizard, then follow every status change from one dashboard.'],
                        ] as $index => [$icon, $title, $copy])
                            <div class="col-md-4">
                                <div class="card h-100 border-0 bg-body">
                                    <div class="card-body">
                                        <div class="d-flex align-items-center gap-3 mb-3">
                                            <span class="sz-step-number">{{ $index + 1 }}</span>
                                            <x-icon :name="$icon" :size="22" class="text-primary" />
                                        </div>
                                        <h3 class="h5 fw-semibold">{{ $title }}</h3>
                                        <p class="text-secondary mb-0">{{ $copy }}</p>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5" id="categories">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="h3 fw-bold mb-2">Browse by field of study</h2>
                <p class="text-secondary mb-0">Jump straight to scholarships in your area, from secondary school to postgraduate.</p>
            </div>

            <div class="row g-3">
                @foreach($fields as $field)
                    <div class="col-6 col-md-4 col-lg-3">
                        <a href="{{ route('scholarships.index', ['field_of_study' => $field]) }}"
                           class="card h-100 text-decoration-none border-0 bg-body-secondary sz-scholarship-card">
                            <div class="card-body d-flex align-items-center gap-2 py-3">
                                <x-icon name="stars" :size="16" class="text-primary flex-shrink-0" />
                                <span class="small fw-semibold text-body">{{ $field }}</span>
                            </div>
                        </a>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section class="py-5 bg-body-secondary">
        <div class="container">
            <div class="text-center mb-5">
                <h2 class="h3 fw-bold mb-2">Why students choose ScholarZim</h2>
                <p class="text-secondary mb-0">Built around one problem: too many students apply to scholarships they were never going to win.</p>
            </div>

            <div class="row g-4">
                @foreach([
                    ['shield-check', 'Verified providers only', 'Every organisation is reviewed, with supporting documents checked, before a single listing goes live.'],
                    ['stars', 'ScholarFit match scoring', 'A 0–100 score across six dimensions shows exactly which criteria you meet and which you still need to work on.'],
                    ['list-ol', 'Guided application wizard', 'A step-by-step flow collects what each provider actually asks for, instead of one generic form.'],
                    ['bell', 'Deadline reminders', 'Save a scholarship and get notified before the window closes, so nothing slips through.'],
                ] as [$icon, $title, $copy])
                    <div class="col-md-6 col-lg-3">
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

    <section class="py-5" id="faq">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-4">
                    <h2 class="h3 fw-bold mb-2">Frequently asked questions</h2>
                    <p class="text-secondary mb-4">
                        Can't find what you're looking for? Reach out after you
                        <a href="{{ route('register') }}">create a free account</a>.
                    </p>
                </div>

                <div class="col-lg-8">
                    <div class="accordion" id="szFaqAccordion">
                        @foreach([
                            [
                                'Is ScholarZim free for students?',
                                'Yes. Creating an account, building your profile, searching listings, and submitting applications are all free for students.',
                            ],
                            [
                                'How does ScholarFit matching work?',
                                'When you complete your profile, ScholarFit compares it against each scholarship\'s stated criteria and produces a score out of 100 across six dimensions — such as education level, field of study, and academic results — so you can see exactly which requirements you meet before you apply.',
                            ],
                            [
                                'How are scholarship providers verified?',
                                'Organisations register separately from students and submit supporting documentation for admin review. Listings only go live once the provider account has been approved.',
                            ],
                            [
                                'What happens after I submit an application?',
                                'Your application status updates on your dashboard as the provider reviews it — submitted, under review, and a final decision — so you always know where you stand without needing to email anyone.',
                            ],
                            [
                                'Can I save scholarships to apply for later?',
                                'Yes. Save any listing from its details page and revisit it from your dashboard; ScholarZim also sends a reminder as its deadline approaches.',
                            ],
                        ] as $index => [$question, $answer])
                            <div class="accordion-item">
                                <h3 class="accordion-header">
                                    <button class="accordion-button {{ $index === 0 ? '' : 'collapsed' }}" type="button"
                                            data-bs-toggle="collapse" data-bs-target="#szFaq{{ $index }}"
                                            aria-expanded="{{ $index === 0 ? 'true' : 'false' }}" aria-controls="szFaq{{ $index }}">
                                        {{ $question }}
                                    </button>
                                </h3>
                                <div id="szFaq{{ $index }}" class="accordion-collapse collapse {{ $index === 0 ? 'show' : '' }}"
                                     data-bs-parent="#szFaqAccordion">
                                    <div class="accordion-body text-secondary">
                                        {{ $answer }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
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

    <section class="py-5">
        <div class="container">
            <div class="text-center py-4">
                <img src="{{ asset('assets/img/scholarship-coin-badge.jpg') }}" alt=""
                     class="sz-badge-coin mb-3" loading="lazy">
                <h2 class="h3 fw-bold mb-2">Ready to see your matches?</h2>
                <p class="text-secondary mb-4 mx-auto" style="max-width: 32rem;">
                    Build your profile in a few minutes and ScholarFit will start scoring open scholarships against it right away.
                </p>
                <div class="d-flex flex-wrap justify-content-center gap-2">
                    <a class="btn btn-primary btn-lg" href="{{ route('register') }}">Create your free account</a>
                    <a class="btn btn-outline-secondary btn-lg" href="{{ route('scholarships.index') }}">Browse scholarships</a>
                </div>
            </div>
        </div>
    </section>

@endsection
