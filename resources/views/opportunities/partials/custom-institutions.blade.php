@props(['opportunity' => null, 'idPrefix' => 'custom'])

@php
    /**
     * "Institution not listed? Enter institution name": institutions the catalogue does not hold, most often universities
     * abroad. Plain rows posted as custom_institutions[n][name|country], so it works without JavaScript. They are shown on
     * the listing; ScholarFit cannot compare a student's profile with them (ProgrammeScope::unlistedInstitutionOutcome).
     * A name that is already in the catalogue is switched to the catalogue institution when the form is saved
     * (ListingScopes::choices), so typing "University of Zimbabwe" never creates a second copy.
     */
    $max = \App\Services\Catalogue\ListingScopes::MAX_CUSTOM_INSTITUTIONS;
    $countryNames = array_values(array_diff(\App\Support\FormOptions::countries(), \App\Support\FormOptions::FLEXIBLE_COUNTRIES));
    $rows = collect((array) old('custom_institutions', $opportunity?->custom_institutions ?? []))
        ->filter(fn ($row) => is_array($row))
        ->all();
    // Always one empty row to type into, and one more once any are filled in.
    $filled = collect($rows)->filter(fn ($row) => filled($row['name'] ?? null) || filled($row['country'] ?? null))->count();
    $next = $rows === [] ? 0 : max(array_map('intval', array_keys($rows))) + 1;

    for ($blank = 0; $blank < ($filled === 0 ? 1 : 2) && count($rows) < $max; $blank++) {
        $rows[$next++] = ['name' => '', 'country' => ''];
    }

    $hasError = collect($errors->keys())->contains(fn ($key) => str_starts_with($key, 'custom_institutions'));
@endphp

<details class="mb-3" data-custom-institutions @if($filled > 0 || $hasError) open @endif>
    <summary class="small">Institution not listed? Enter institution name</summary>
    <p class="form-text mb-2">
        For an institution that is not in our list, including universities and colleges outside Zimbabwe. It is shown to
        students on the listing. We cannot check a student's profile against it, so it does not decide who qualifies.
    </p>
    @error('custom_institutions')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

    @foreach($rows as $i => $row)
        <div class="row g-2 mb-2">
            <div class="col-md-7">
                <label class="form-label small" for="{{ $idPrefix }}-custom-name-{{ $i }}">Institution name</label>
                <input class="form-control @error('custom_institutions.' . $i . '.name') is-invalid @enderror"
                       id="{{ $idPrefix }}-custom-name-{{ $i }}" name="custom_institutions[{{ $i }}][name]"
                       maxlength="{{ \App\Services\Catalogue\ListingScopes::MAX_CUSTOM_NAME_LENGTH }}"
                       value="{{ $row['name'] ?? '' }}" placeholder="e.g. University of Cape Town">
                @error('custom_institutions.' . $i . '.name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="col-md-5">
                <label class="form-label small" for="{{ $idPrefix }}-custom-country-{{ $i }}">Country (optional)</label>
                <select class="form-select @error('custom_institutions.' . $i . '.country') is-invalid @enderror"
                        id="{{ $idPrefix }}-custom-country-{{ $i }}" name="custom_institutions[{{ $i }}][country]">
                    <option value="">Not stated</option>
                    @foreach($countryNames as $name)
                        <option value="{{ $name }}" @selected(($row['country'] ?? '') === $name)>{{ $name }}</option>
                    @endforeach
                </select>
                @error('custom_institutions.' . $i . '.country')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    @endforeach
    <p class="form-text mb-0">Need to add another? Save the listing, then edit it. Up to {{ $max }} in all.</p>
</details>
