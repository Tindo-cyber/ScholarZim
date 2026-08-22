@props(['label', 'tone' => 'secondary', 'icon' => null])

<span {{ $attributes->merge(['class' => "badge rounded-pill bg-{$tone}-subtle text-{$tone} d-inline-flex align-items-center gap-1"]) }}>
    @if($icon)
        <x-icon :name="$icon" :size="14" />
    @endif
    {{ $label }}
</span>
