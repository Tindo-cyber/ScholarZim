@props([
    /**
     * Column headings, in order. Each is either a string, or an array:
     *   ['label' => 'Actions', 'align' => 'end', 'width' => '2.5rem', 'hidden' => true]
     * `hidden` renders the heading for screen readers only - the selection and
     * action columns have no visible title but still need a name.
     */
    'columns' => [],
    'empty' => false,
    'emptyTitle' => 'Nothing here yet',
    'emptyMessage' => null,
    'emptyIcon' => 'inbox',
    /** Off for tables whose rows are not individually actionable. */
    'hover' => true,
    /**
     * Stacking is what makes a table readable on a phone, and it is on by
     * default. Turn it off only for a table whose columns genuinely have to be
     * compared across a row - an analytics matrix - where cards would lose the
     * comparison that is the point of the table.
     */
    'stack' => true,
    'size' => null,
])

{{--
    The chrome around a data table, so no view has to remember it.

    What it owns: the .table-responsive wrapper, the table classes, the header
    row, the stacking class that turns rows into cards below md, and the empty
    row - including its colspan, which is the part that quietly rots. A column
    added to a table whose empty row still says colspan="4" leaves the empty
    state short of the table's width, and nobody notices because nobody looks
    at a table and its empty state on the same day.

    What it does not own: the rows. They stay in the view, written as ordinary
    <tr> with <x-data-table.cell> children, because every one of these tables
    renders something different - an avatar, a badge, a dropdown, a confirm
    dialog - and a component that tried to take that over would need a callback
    per column and would be harder to read than the markup it replaced.

    Stacking is the .sz-table-stack implementation this codebase already has,
    not a second one. The cell component's data-label is what feeds it.

    NOT for the academic-results or subject-requirements grids. Those are
    editable grids with their own JavaScript, their own <template> rows and
    their own selectors; they already stack correctly and they are not tables of
    records.
--}}
@php
    $normalised = array_map(
        static fn ($column) => is_array($column) ? $column : ['label' => $column],
        $columns
    );
@endphp

<div class="table-responsive">
    <table {{ $attributes->merge([
        'class' => 'table align-middle mb-0'
            . ($hover ? ' table-hover' : '')
            . ($size ? ' table-' . $size : '')
            . ($stack ? ' sz-table-stack' : ''),
    ]) }}>
        @if($normalised !== [])
            <thead>
                <tr>
                    @foreach($normalised as $column)
                        <th scope="col"
                            @class(['text-end' => ($column['align'] ?? null) === 'end'])
                            @if(isset($column['width'])) style="width: {{ $column['width'] }}" @endif>
                            @if($column['hidden'] ?? false)
                                <span class="visually-hidden">{{ $column['label'] }}</span>
                            @else
                                {{ $column['label'] }}
                            @endif
                        </th>
                    @endforeach
                </tr>
            </thead>
        @endif

        <tbody>
            @if($empty)
                {{-- colspan counted from the columns given, so it cannot fall behind them. --}}
                <tr>
                    <td colspan="{{ max(count($normalised), 1) }}">
                        <x-empty-state :title="$emptyTitle" :message="$emptyMessage" :icon="$emptyIcon" />
                    </td>
                </tr>
            @else
                {{ $slot }}
            @endif
        </tbody>

        @isset($foot)
            <tfoot>{{ $foot }}</tfoot>
        @endisset
    </table>
</div>
