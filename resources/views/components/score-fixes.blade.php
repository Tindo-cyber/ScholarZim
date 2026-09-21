@props([
    'fit',
    /** 'alert' on a detail page where there is room; 'inline' in a list card. */
    'variant' => 'inline',
    'title' => 'To improve your score',
])

@php
    /**
     * What is holding a match score back, and where to go and fix it.
     *
     * The same block was written out three times - the recommendations list,
     * the scholarship detail page and the application wizard - each with its own
     * copy of the profile/documents link logic. One of the three is now enough.
     *
     * Every line comes from $breakdown->fixes, which the matchers filled in when
     * they scored. Nothing is recalculated and nothing is suggested that the
     * engine did not already flag; telling a student what is missing without
     * saying where to put it is only half an answer, and that "where" is the
     * fix's own target, not a guess made here.
     */
    $fixes = $fit->breakdown->fixes;
@endphp

@if($fixes)
    @if($variant === 'alert')
        <div {{ $attributes->merge(['class' => 'alert alert-warning small']) }}>
            <div class="fw-semibold mb-1 d-flex align-items-center gap-2">
                <x-icon name="trend" :size="16" />{{ $title }}
            </div>
            <ul class="mb-0 ps-3 d-grid gap-1">
                @foreach($fixes as $fix)
                    <li>
                        {{ \App\Support\ScholarFitCopy::humanise($fix['text']) }}
                        <x-score-fixes.link :fix="$fix" />
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <details {{ $attributes->merge(['class' => 'small']) }}>
            <summary class="text-secondary">
                {{ count($fixes) }} {{ \Illuminate\Support\Str::plural('thing', count($fixes)) }} holding this score back
            </summary>
            <ul class="mt-2 mb-0 ps-3 text-secondary d-grid gap-1">
                @foreach($fixes as $fix)
                    <li>
                        {{ \App\Support\ScholarFitCopy::humanise($fix['text']) }}
                        <x-score-fixes.link :fix="$fix" />
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
@endif
