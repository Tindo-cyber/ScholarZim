@props(['stages' => [], 'label' => 'Application progress'])

{{-- Horizontal progress rail for an application's lifecycle. A stage may carry a 'date', shown under its label. --}}
<ol aria-label="{{ $label }}" {{ $attributes->merge(['class' => 'sz-timeline list-unstyled d-flex gap-0 mb-0']) }}>
    @foreach($stages as $index => $stage)
        @php
            $tone = $stage['tone'] ?? 'primary';
            $done = (bool) ($stage['done'] ?? false);
        @endphp

        <li class="sz-timeline-step flex-fill text-center {{ $done ? 'is-done' : '' }}">
            <span class="sz-timeline-dot bg-{{ $done ? $tone : 'body-secondary' }} text-{{ $done ? 'white' : 'secondary' }}">
                @if($done)
                    <x-icon name="check" :size="14" />
                @else
                    {{ $index + 1 }}
                @endif
            </span>
            <span class="d-block small mt-2 {{ $done ? 'fw-semibold' : 'text-secondary' }}">{{ $stage['label'] }}</span>
            <span class="visually-hidden">{{ $done ? 'Step reached' : 'Not reached yet' }}</span>
            @if(! empty($stage['date']))
                <span class="d-block small text-secondary sz-tabular">{{ $stage['date'] }}</span>
            @endif
        </li>
    @endforeach
</ol>
