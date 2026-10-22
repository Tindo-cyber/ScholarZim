@props([
    'name',
    'id',
    'label',
    'groups',
    'noun' => 'choices',
    'help' => null,
    // The id of the level <select> whose value narrows the list (options carry data-level), when there is one.
    'levelSelect' => null,
    // The level chosen now (canonical, or null). Only the groups at this level, and any holding a saved choice, are
    // written into the page; the rest wait, inert, in a <template> that scope-picker.js opens when the level changes.
    'activeLevel' => null,
    'errorKey' => null,
    // What the list holds, for the prompt shown before a level is chosen.
    'levelNoun' => 'programmes',
])

@php
    $live = [];
    $reserve = [];

    foreach (array_values(array_filter($groups, fn ($g) => ! empty($g['options']))) as $n => $g) {
        $g['order'] = $n;

        if ($levelSelect === null || ($g['level'] ?? null) === $activeLevel || collect($g['options'])->contains(fn ($o) => $o['selected'] ?? false)) {
            $live[] = $g;
        } else {
            $reserve[] = $g;
        }
    }
@endphp

{{--
    A checkbox list you can search, with what is chosen shown above it and removable: the replacement for a multiple
    <select> that needed Ctrl/Cmd to choose more than one.

    $groups: [['label' => 'Undergraduate - Accounting', 'level' => 'UNDERGRADUATE', 'options' => [
        ['id' => 5, 'label' => 'Bachelor of Commerce in Accounting', 'sub' => 'Accounting', 'search' => 'lower case', 'selected' => bool], ...]], ...]

    Without JavaScript this is an ordinary group of checkboxes posting `name[]`, every option visible. resources/js/scope-picker.js
    adds the search box, the chosen list, and the level filter. The server checks every id again (ListingScopes::problems).
--}}
<fieldset class="mb-3" data-scope-picker data-noun="{{ $noun }}" id="{{ $id }}"
          @if($levelSelect) data-filter-level="{{ $levelSelect }}" @endif
          @if($help) aria-describedby="{{ $id }}-help" @endif>
    <legend class="form-label">{{ $label }}</legend>
    @if($help)<div class="form-text mb-1" id="{{ $id }}-help">{{ $help }}</div>@endif

    <div class="mb-2" data-picker-chosen hidden>
        <span class="small fw-semibold">Chosen (<span data-picker-count>0</span>)</span>
        <ul class="list-inline mb-0 mt-1" data-picker-chips aria-live="polite"></ul>
    </div>

    <input type="search" class="form-control form-control-sm mb-1" data-picker-search hidden
           placeholder="Search by name or abbreviation" aria-label="Search {{ $noun }}">
    <div class="small text-warning-emphasis mb-1" data-picker-note role="status" hidden></div>

    <div class="border rounded p-2 overflow-auto @if($errors->has($errorKey ?? $name)) border-danger @endif" style="max-height: 18rem" data-picker-list>
        @foreach($live as $group)
            @include('components.scope-picker-group', ['group' => $group, 'name' => $name, 'id' => $id])
        @endforeach
        <div class="small text-secondary" data-picker-empty hidden>Nothing matches. Try another word, or check the level chosen above.</div>
        @if($levelSelect)<div class="small text-secondary" data-picker-prompt hidden>Choose a level of study above to list its {{ $levelNoun }}, or type a name to search every level.</div>@endif
    </div>
    @if($reserve)
        <template data-picker-reserve>@foreach($reserve as $group)@include('components.scope-picker-group', ['group' => $group, 'name' => $name, 'id' => $id])@endforeach</template>
    @endif

    @error($errorKey ?? $name)<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
    @foreach($errors->get(($errorKey ?? $name) . '.*') as $messages)<div class="invalid-feedback d-block">{{ $messages[0] }}</div>@endforeach
</fieldset>
