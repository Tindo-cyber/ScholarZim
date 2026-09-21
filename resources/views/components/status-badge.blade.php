@props(['label', 'tone' => 'secondary', 'icon' => null])

@php
    /**
     * One icon per status word, for the whole application.
     *
     * Colour on its own is not an information channel (WCAG 1.4.1), and these
     * badges lean on it hard: "Accepted" and "Rejected" are the same pill in
     * two hues. The icon is the second channel, and the text is the third.
     *
     * The map is keyed on the label rather than passed in by each caller, so
     * "Pending" cannot be an hourglass on one page and a clock on the next.
     * Callers that know better still pass icon="..." and win; callers with a
     * label nobody has mapped get no icon rather than a guessed one.
     *
     * Every name here exists in components/icon.blade.php. There is no second
     * icon set and no emoji.
     */
    $icons = [
        // Waiting on somebody
        'pending' => 'hourglass-split',
        'pending approval' => 'hourglass-split',
        'awaiting review' => 'hourglass-split',
        'awaiting decision' => 'hourglass-split',
        'under review' => 'clock-history',
        'in review' => 'clock-history',
        'draft' => 'clock-history',

        // Went well
        'accepted' => 'check-circle',
        'approved' => 'check-circle',
        'published' => 'check-circle',
        'active' => 'check-circle',
        'open' => 'check-circle',
        'verified' => 'shield-check',
        'shortlisted' => 'stars',
        'eligible' => 'check-circle',
        'applied' => 'check-circle',
        'saved' => 'bookmark',

        // Did not
        'rejected' => 'x-circle',
        'declined' => 'x-circle',
        'not eligible' => 'x-circle',
        'no stated requirements' => 'shield',

        // Ended, by someone's choice or by the calendar
        'withdrawn' => 'circle',
        'closed' => 'lock',
        'inactive' => 'circle',
        'archived' => 'lock',
        'expired' => 'clock-history',
        'suspended' => 'person-exclamation',
    ];

    $resolved = $icon ?? ($icons[mb_strtolower(trim((string) $label))] ?? null);
@endphp

<span {{ $attributes->merge(['class' => "badge rounded-pill bg-{$tone}-subtle text-{$tone} d-inline-flex align-items-center gap-1"]) }}>
    @if($resolved)
        <x-icon :name="$resolved" :size="14" />
    @endif
    {{ $label }}
</span>
