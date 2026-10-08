@php
    $links = [
        'overview' => ['Overview', route('admin.catalogue')],
        'programmes' => ['Programmes', route('admin.catalogue.programmes')],
        'fields' => ['Fields', route('admin.catalogue.fields')],
        'institutions' => ['Institutions', route('admin.catalogue.institutions')],
        'pending' => ['Waiting for approval', route('admin.catalogue.pending')],
    ];
@endphp
<ul class="nav nav-pills mb-4 gap-1" aria-label="Catalogue sections">
    @foreach($links as $key => [$label, $url])
        <li class="nav-item">
            <a class="nav-link py-1 @if($active === $key) active @endif" href="{{ $url }}" @if($active === $key) aria-current="page" @endif>{{ $label }}</a>
        </li>
    @endforeach
</ul>
