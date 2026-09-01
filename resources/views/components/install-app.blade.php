@props(['class' => 'btn btn-sm btn-outline-primary'])

{{--
    The install offer.

    Hidden in the markup and revealed by resources/js/pwa.js only when the
    browser fires beforeinstallprompt - which it does when the site actually
    meets the install criteria and is not installed already. So this never
    appears in an installed window, never appears on a browser that cannot
    install, and never appears twice: pwa.js remembers a dismissal.

    It is a button in the existing navigation rather than a banner or a modal.
    Nothing is covered, nothing has to be closed, and a reader who is not
    interested never has to interact with it.
--}}
<button type="button" {{ $attributes->merge(['class' => $class]) }} data-pwa-install hidden>
    <x-icon name="download" />
    <span>Install app</span>
</button>
