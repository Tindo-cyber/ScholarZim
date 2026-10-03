@extends('layouts.public')

@section('title', 'How ScholarFit works')

@section('meta_description', 'ScholarFit compares your profile against what each scholarship states it requires, and tells you whether you qualify - with the reason either way.')

@section('content')

    <section class="py-5">
        <div class="container py-lg-3">
            <div class="row g-5 align-items-start">
                <div class="col-lg-5">
                    <p class="sz-eyebrow mb-2">ScholarFit</p>
                    <h1 class="h2 fw-bold mb-3">How ScholarFit works</h1>
                    <p class="fs-5 text-secondary mb-4">
                        ScholarFit reads what a scholarship says it wants and checks it against what you
                        have recorded. It answers one question: do you meet what this listing requires?
                    </p>

                    {{--
                        Said plainly, because the distinction is the whole design
                        of the engine and the easiest thing for a visitor to
                        misread. ScholarFit checks eligibility; it does not admit
                        anyone, and the provider decides every award.
                    --}}
                    <div class="alert alert-primary d-flex gap-2 mb-0" role="note">
                        <x-icon name="shield" :size="18" class="flex-shrink-0 mt-1" />
                        <div>
                            <p class="fw-semibold mb-1">Eligibility is not an award.</p>
                            <p class="mb-0 small">
                                ScholarFit tells you whether you meet what a listing states it requires -
                                not who wins it. Every award is decided by the provider who posted the
                                scholarship.
                            </p>
                        </div>
                    </div>
                </div>

                <div class="col-lg-7">
                    <ol class="list-unstyled d-grid gap-3 mb-4">
                        @foreach([
                            ['Build your profile', 'Education level, field of study, results and supporting documents.'],
                            ['Understand eligibility', 'Each listing states its own requirements. You are told which you meet and which you do not, with the actual figures.'],
                            ['Apply and track', 'Submit through the guided form and follow the status until the provider decides.'],
                        ] as $index => [$title, $copy])
                            <li class="card">
                                <div class="card-body d-flex gap-3">
                                    <span class="sz-step-number flex-shrink-0">{{ $index + 1 }}</span>
                                    <div class="min-w-0">
                                        <h2 class="h6 fw-semibold mb-1">{{ $title }}</h2>
                                        <p class="small text-secondary mb-0">{{ $copy }}</p>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>

                    <div class="d-flex flex-wrap gap-2">
                        <a class="btn btn-primary" href="{{ route('scholarships.index') }}">Find scholarships</a>
                        @guest
                            <a class="btn btn-outline-secondary" href="{{ route('register') }}">Create a free account</a>
                        @endguest
                    </div>
                </div>
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
                                'Once your profile has your education level, field of study and results, ScholarFit compares it against what each scholarship states it requires. It tells you whether you are eligible and explains exactly which requirements you meet and which you do not, with the actual figures either way. It ranks listings for you; it does not decide who is awarded.',
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

@endsection
