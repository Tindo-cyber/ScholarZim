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
                       ? 'Where the organisation is legally registered, as shown on its registration document. This is a record for review, not proof - check it against the document.'
                       : 'The country where your organisation is legally registered. This is not where your scholarships are held: you choose each scholarship\'s study destination when you post it.'" />
</div>

<x-form.input name="registration_number" :id="$idPrefix . '-number'"
              :label="$forAdmin ? 'Registration number' : 'Official registration number'"
              :value="$profile?->registration_number" :bag="$bag" maxlength="100" autocomplete="off"
              hint="As it appears on the registration document - for example a company, charity, NPO, trust or PVO number. Any format your country uses is accepted." />

<x-form.textarea name="registration_number_note" :id="$idPrefix . '-number-note'"
                 :label="$forAdmin ? 'Why there is no registration number' : 'No registration number? Tell us why'"
                 :value="$profile?->registration_number_note" :bag="$bag" :rows="2" maxlength="500"
                 :hint="$forAdmin
                     ? 'Optional here. Leave blank if the number is recorded above.'
                     : 'Only if your organisation has no official number - for example a government body or public university established by law. Upload the document that establishes it instead, and an administrator will review it.'" />
