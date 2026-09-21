@props(['label'])

{{--
    A labelled group inside the sidebar's single <ul>.

    The label is a real list item carrying its own text rather than a ::before
    on the first link, so a screen reader reads "Overview" before the items in
    it. Keeping every group in one flat list is what lets Bootstrap's .nav-pills
    keep working - nesting a <ul> per group would need its own nav semantics and
    would indent the links for no gain.
--}}
<li class="sz-nav-group" role="presentation">
    <span class="sz-nav-group-label d-block">{{ $label }}</span>
</li>

{{ $slot }}
