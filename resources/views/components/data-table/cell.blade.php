@props([
    /**
     * The column this cell belongs to, repeated as its heading when the row is
     * a card on a phone. Null for a cell that has no heading to repeat - a
     * selection checkbox, a row's actions - which prints no label rather than
     * an empty gutter.
     */
    'label' => null,
    'align' => null,
])

{{--
    One cell of an <x-data-table> row.

    It exists for the data-label attribute. Below md a row is redrawn as a card
    and each cell prints its own heading from that attribute; a cell that
    forgets it loses its heading on a phone, and the value is left sitting on
    its own with nothing to say what it is. Writing the label here, next to the
    value, is harder to forget than writing it on a <td> by hand - and it is the
    same attribute .sz-table-stack already reads, not a new mechanism.
--}}
<td {{ $attributes->merge(['class' => $align === 'end' ? 'text-end' : '']) }}
    data-label="{{ $label }}">{{ $slot }}</td>
