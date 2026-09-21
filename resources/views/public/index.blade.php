@extends('layouts.public')

@section('title', 'Scholarships for Zimbabwean students')

@section('meta_description', 'Search open scholarships, see how well each one fits your profile, and apply and track everything in one place — free for Zimbabwean students.')

@section('content')

    {{--
        The landing page answers three questions above the fold - what this is,
        who it is for, and what you can do here - and then stops selling.

        Two sections and two images were removed rather than restyled. A photo
        banner captioned "Real Zimbabwean students. Real scholarships." over
        stock photography claimed something the picture could not support, and a
        closing call to action repeated the hero's two buttons under a
        decorative coin. Every remaining claim is something the application
        actually does; where a sentence could not be traced to behaviour, it is
        gone rather than softened.
    --}}
    <section class="sz-hero py-5">
        <div class="container py-lg-4">
            <div class="row align-items-center g-5">
                <div class="col-lg-7">
                    <p class="sz-eyebrow mb-2">ScholarZim</p>

                    <h1 class="h1 fw-bold mb-3">
                        Find scholarship opportunities and manage your
                        applications in one place.
                    </h1>

                    <p class="fs-5 text-secondary mb-4">
                        A free service for Zimbabwean students. Search what is open now, see how each
                        scholarship's stated requirements line up with your profile, and keep every
                        application and deadline together.
                    </p>

                    {{--
                        Stacked below sm, joined above it.

                        As an .input-group the field and a button labelled "Find
                        scholarships" shared 360px, which left the field about
                        120px wide - five characters of a placeholder that asks
                        you to type a subject. The button keeps its full label
                        and takes its own line instead.
                    --}}
                    <form action="{{ route('scholarships.index') }}" method="GET" class="mb-3">
                        <label class="visually-hidden" for="sz-hero-search">Search scholarships</label>
                        <div class="d-flex flex-column flex-sm-row gap-2">
                            <input type="search" class="form-control form-control-lg" id="sz-hero-search" name="keyword"
                                   placeholder="Try &quot;engineering&quot; or &quot;Masters&quot;">
                            <button class="btn btn-primary btn-lg px-4 flex-shrink-0" type="submit">Find scholarships</button>
                        </div>
                    </form>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-outline-secondary" href="{{ route('scholarships.index') }}">
                            Browse everything open
                        </a>
                        @guest
                            <a class="btn btn-outline-secondary" href="{{ route('register') }}">Create an account</a>
                        @endguest
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
                            <h2 class="sz-eyebrow mb-3">On ScholarZim right now</h2>
                            <div class="row g-3 text-center text-sm-start">
                                @foreach([
                                    ['Open scholarships', $stats['activeScholarships'], 'accepting applications today'],
                                    ['Closing this month', $stats['closingSoon'], 'within the next 30 days'],
                                    ['Students registered', $stats['students'], 'with a ScholarZim account'],
                                    ['Scholarships granted', $stats['awardsMade'], 'applications accepted so far'],
                                ] as [$label, $value, $hint])
                                    <div class="col-6">
                                        <div class="fs-3 fw-bold lh-1 sz-tabular">{{ number_format($value) }}</div>
                                        <div class="small fw-semibold mt-1">{{ $label }}</div>
                                        <div class="small text-secondary">{{ $hint }}</div>
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
                <h2 class="h3 fw-bold mb-2">What you can do here</h2>
                <p class="text-secondary mb-0">Four things, and they all work off one profile.</p>
            </div>

            <div class="row g-4">
                @foreach([
                    ['search', 'Discover opportunities', 'Search and filter every published scholarship by field of study, education level, province, funding type and closing date.'],
                    ['person', 'Build your applicant profile', 'Record your education level, field, results and documents once. Everything else on the platform reads from it.'],
                    ['stars', 'See how well each one fits', 'ScholarFit compares your profile against what a listing states and scores it out of 100, showing the reason for every point.'],
                    ['file-text', 'Apply and track', 'Apply through a guided form, then follow each application from submitted to the provider\'s decision and their reason for it.'],
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

    <section class="py-5" id="how-it-works">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-5">
                    <h2 class="h3 fw-bold mb-2">How ScholarFit works</h2>
                    <p class="text-secondary mb-3">
                        ScholarFit reads what a scholarship says it wants and compares it with what you
                        have recorded. It answers two separate questions, in this order.
                    </p>

                    {{--
                        Said plainly, because the distinction is the whole design
                        of the engine and the easiest thing for a visitor to
                        misread. ScholarFit ranks; it does not admit anyone, and
                        the provider decides every award.
                    --}}
                    <div class="alert alert-primary d-flex gap-2 mb-0" role="note">
                        <x-icon name="shield" :size="18" class="flex-shrink-0 mt-1" />
                        <div>
                            <p class="fw-semibold mb-1">A match score is not a decision.</p>
                            <p class="mb-0 small">
                                Eligibility is answered first, from the rules the provider states. The score
                                only ranks how closely you fit. Every award is decided by the provider who
                                posted the scholarship.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <ol class="list-unstyled d-grid gap-3 mb-0">
                        @foreach([
                            ['Build your profile', 'Education level, field of study, results and supporting documents.'],
                            ['Understand eligibility', 'Each listing states its own requirements. You are told which you meet and which you do not, with the actual figures.'],
                            ['See how well an opportunity matches', 'A score out of 100 across six dimensions, each one shown with the reason it scored what it did.'],
                            ['Apply and track', 'Submit through the guided form and follow the status until the provider decides.'],
                        ] as $index => [$title, $copy])
                            <li class="card">
                                <div class="card-body d-flex gap-3">
                                    <span class="sz-step-number flex-shrink-0">{{ $index + 1 }}</span>
                                    <div class="min-w-0">
                                        <h3 class="h6 fw-semibold mb-1">{{ $title }}</h3>
                                        <p class="small text-secondary mb-0">{{ $copy }}</p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </section>

    <section class="py-5 bg-body-secondary" id="featured">
        <div class="container">
            <div class="d-flex flex-wrap gap-2 align-items-end justify-content-between mb-4">
                <div>
                    <h2 class="h3 fw-bold mb-1">Closing soon</h2>
                    <p class="text-secondary mb-0">Published listings, nearest deadline first.</p>
                </div>
                <a class="btn btn-outline-secondary" href="{{ route('scholarships.index') }}">Browse all</a>
            </div>

            @if($featured->isEmpty())
                <div class="card">
                    <x-empty-state title="No open scholarships right now"
                                   message="A listing appears here once its provider has submitted it and an administrator has approved it. Nothing is waiting to be published at the moment."
                                   icon="stars"
                                   action-label="Browse the full list"
                                   :action-href="route('scholarships.index')" />
                </div>
            @else
                <div class="row g-3 g-lg-4">
                    @foreach($featured as $opportunity)
                        <div class="col-md-6 col-lg-4">
                            <x-scholarship-card :opportunity="$opportunity"
                                                :saved="in_array($opportunity->opportunity_id, $savedIds, true)"
                                                :applied="in_array($opportunity->opportunity_id, $appliedIds, true)"
                                                :accepted="$accepted[$opportunity->opportunity_id] ?? null" />
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    <section class="py-5" id="categories">
        <div class="container">
            <div class="mb-4">
                <h2 class="h3 fw-bold mb-2">Browse by field of study</h2>
                <p class="text-secondary mb-0">From secondary school through to postgraduate.</p>
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

    <section class="py-5 bg-body-secondary" id="faq">
        <div class="container">
            <div class="row g-5">
                <div class="col-lg-4">
                    <h2 class="h3 fw-bold mb-2">Questions</h2>
                    <p class="text-secondary mb-0">
                        Four things students ask before they sign up.
                    </p>
                </div>

                <div class="col-lg-8">
                    <div class="accordion" id="szFaqAccordion">
                        @foreach([
                            [
                                'Is ScholarZim free for students?',
                                'Yes. Creating an account, building your profile, searching listings and submitting applications are all free. There is no payment step anywhere in the service.',
                            ],
                            [
                                'How does ScholarFit matching work?',
                                'Once your profile has your education level, field of study and results, ScholarFit compares it against what each scholarship states it requires. It first answers whether you meet the stated rules, then scores how closely you fit across six dimensions, and shows the reason behind each part of the score. It ranks listings for you; it does not decide who is awarded.',
                            ],
                            [
                                'How do scholarships get onto ScholarZim?',
                                'Organisations register separately from students and upload a registration certificate. They cannot publish anything until an administrator approves the organisation, and each listing they then submit is reviewed by an administrator before it appears publicly.',
                            ],
                            [
                                'What happens after I submit an application?',
                                'It sits with the provider who posted the scholarship. Your dashboard shows the status, and when they decide you see whether it was accepted or rejected together with the reason they gave.',
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
                                Publish your programme, review applications in one inbox, and keep every
                                decision on record. Register your organisation and upload its registration
                                certificate; an administrator approves the account before you can publish,
                                and reviews each listing before it goes public.
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
