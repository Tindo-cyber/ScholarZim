@extends('layouts.app')

@section('title', 'ScholarFit weights')

@section('content')

    <x-page-header title="ScholarFit weights"
                   subtitle="How much each dimension contributes to a student's match score."
                   eyebrow="Administrator">
        <x-slot:actions>
            @unless($isDefault)
                <form method="POST" action="{{ route('admin.scholarfit.reset') }}" class="m-0">
                    @csrf
                    <button class="btn btn-outline-secondary" type="submit">Reset to defaults</button>
                </form>
            @endunless
        </x-slot:actions>
    </x-page-header>

    <div class="row g-4">
        <div class="col-xl-8">
            <form method="POST" action="{{ route('admin.scholarfit.update') }}" id="scholarfit-weights-form">
                @csrf

                <div class="card mb-4">
                    <div class="card-header d-flex align-items-center justify-content-between">
                        <h2 class="h6 fw-semibold mb-0">Dimension weights</h2>
                        <x-status-badge :label="$isDefault ? 'Shipped defaults' : 'Customised'"
                                        :tone="$isDefault ? 'secondary' : 'primary'" />
                    </div>

                    <div class="card-body">
                        <p class="text-secondary">
                            Every score is presented to students as a percentage, so the six weights must total
                            exactly 100. Raising one means lowering another - that is the whole trade-off, and
                            the running total below shows where you are.
                        </p>

                        @error('weights')
                            <div class="alert alert-danger" role="alert">{{ $message }}</div>
                        @enderror

                        <div class="d-grid gap-3" data-weights-group>
                            @foreach($labels as $key => $label)
                                <div class="row g-2 align-items-center">
                                    <div class="col-5 col-md-4">
                                        <label class="form-label mb-0" for="weight-{{ $key }}">{{ $label }}</label>
                                        <div class="small text-secondary">Default {{ $defaults[$key] }}</div>
                                    </div>
                                    <div class="col-4 col-md-6">
                                        <input type="range" class="form-range" min="0" max="60" step="1"
                                               id="weight-range-{{ $key }}"
                                               value="{{ old('weights.' . $key, $weights[$key]) }}"
                                               data-weight-range="{{ $key }}"
                                               aria-label="{{ $label }} weight slider">
                                    </div>
                                    <div class="col-3 col-md-2">
                                        <input type="number" class="form-control" min="0" max="100" step="1"
                                               id="weight-{{ $key }}"
                                               name="weights[{{ $key }}]"
                                               value="{{ old('weights.' . $key, $weights[$key]) }}"
                                               data-weight-input="{{ $key }}"
                                               aria-label="{{ $label }} weight">
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <hr>

                        <div class="d-flex align-items-center justify-content-between">
                            <span class="fw-semibold">Total</span>
                            <span class="fs-4 fw-bold" data-weights-total aria-live="polite">
                                {{ array_sum($weights) }}
                            </span>
                        </div>
                        <div class="progress mt-2" style="height:.5rem;">
                            <div class="progress-bar" data-weights-bar
                                 style="width: {{ min(100, array_sum($weights)) }}%"></div>
                        </div>
                        <p class="form-text mb-0" data-weights-message>Weights must add up to 100.</p>
                    </div>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button class="btn btn-primary btn-lg" type="submit">Save weights</button>
                    <a class="btn btn-outline-secondary btn-lg" href="{{ route('admin.dashboard') }}">Cancel</a>
                </div>
            </form>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">Worked example</h2>
                </div>
                <div class="card-body">
                    <p class="small text-secondary">
                        A complete undergraduate profile scored against a listing it matches on every
                        dimension, under the weights currently in force.
                    </p>

                    <div class="text-center mb-3">
                        <x-match-score :score="$sample['score']" size="lg" label="Perfect match" />
                    </div>

                    <ul class="list-unstyled d-grid gap-2 mb-0 small">
                        @foreach($sample['dimensions'] as $dimension)
                            <li>
                                <div class="d-flex justify-content-between">
                                    <span>{{ $dimension['label'] }}</span>
                                    <span class="text-secondary">{{ $dimension['score'] }} / {{ $dimension['max'] }}</span>
                                </div>
                                <div class="progress mt-1" style="height:.25rem;">
                                    <div class="progress-bar bg-primary"
                                         style="width: {{ $dimension['max'] > 0 ? round($dimension['score'] / $dimension['max'] * 100) : 0 }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="h6 fw-semibold mb-0">What these do not control</h2>
                </div>
                <div class="card-body small">
                    <p>
                        Hard eligibility rules - minimum points, age limits, citizenship, province, and a
                        required results certificate - are set per listing by the provider. A student who
                        fails one is shown as ineligible and scores nothing, whatever these weights say.
                    </p>
                    <p>
                        A near miss (a related field of study, or an adjacent education level) earns
                        {{ $relatedCredit }}% of the dimension's weight rather than nothing.
                    </p>
                    <p class="mb-0">
                        A score of {{ $confidence['high'] }} or more reads as a strong match,
                        {{ $confidence['medium'] }} or more as a possible one. Changing a weight changes every
                        score immediately; archived reports keep the numbers they were generated with.
                    </p>
                </div>
            </div>
        </div>
    </div>

@endsection
