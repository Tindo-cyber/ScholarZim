{{--
    Country of registration, registration number, and the explanation for an
    organisation that has none. Shared by provider registration, the provider's
    own update form and the administrator's review page, so the wording and the
    rules (App\Support\ProviderRegistrationDetails) cannot drift between them.

    $countries  code => name (App\Support\Countries::options())
    $profile    the ProviderProfile being edited, or null at registration
    $bag        the error bag the surrounding form validates into
    $idPrefix   keeps ids unique when more than one copy is on a page
    $audience   'organisation' (the default) or 'admin', which only changes the wording
--}}
@php
    $bag = $bag ?? 'default';
    $idPrefix = $idPrefix ?? 'reg';
    $forAdmin = ($audience ?? 'organisation') === 'admin';
@endphp

<div data-searchable-select>
    <x-form.select name="registration_country" :id="$idPrefix . '-country'"
                   label="Country of registration" :options="$countries"
                   :value="$profile?->registration_country" :bag="$bag"
                   placeholder="Select a country" required autocomplete="country"
                   :hint="$forAdmin
                       ? 'Where the organisation is legally registered. Check it against the document.'
                       : 'The country where your organisation is registered, not where your scholarships are held.'" />
</div>

<x-form.input name="registration_number" :id="$idPrefix . '-number'"
              :label="$forAdmin ? 'Registration number' : 'Official registration number'"
              :value="$profile?->registration_number" :bag="$bag" maxlength="100" autocomplete="off"
              hint="As shown on the registration document, e.g. a company, charity, NPO, trust or PVO number." />

<x-form.textarea name="registration_number_note" :id="$idPrefix . '-number-note'"
                 :label="$forAdmin ? 'Why there is no registration number' : 'No registration number? Tell us why'"
                 :value="$profile?->registration_number_note" :bag="$bag" :rows="2" maxlength="500"
                 :hint="$forAdmin
                     ? 'Optional here. Leave blank if the number is recorded above.'
                     : 'Only if you have no official number, e.g. a government body. Upload the document that establishes your organisation.'" />
