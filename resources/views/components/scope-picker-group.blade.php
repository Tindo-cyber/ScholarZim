@props(['group', 'name', 'id'])
{{-- One heading and its checkboxes, written tightly: a programme list repeats this about 1,400 times. --}}
<div data-picker-group data-order="{{ $group['order'] ?? 0 }}" @if(! empty($group['level'])) data-level="{{ $group['level'] }}" @endif @if(! empty($group['field'])) data-field="{{ $group['field'] }}" @endif>
<div class="small fw-semibold text-secondary mt-1">{{ $group['label'] }}</div>
@foreach($group['options'] as $option)
<div class="form-check" data-picker-option data-search="{{ $option['search'] ?? mb_strtolower($option['label']) }}" @if(! empty($group['level'])) data-level="{{ $group['level'] }}" @endif><input class="form-check-input" type="checkbox" name="{{ $name }}[]" value="{{ $option['id'] }}" id="{{ $id }}-{{ $option['id'] }}" @checked($option['selected'] ?? false) @foreach(($option['data'] ?? []) as $dataKey => $dataValue) data-{{ $dataKey }}="{{ $dataValue }}" @endforeach><label class="form-check-label" for="{{ $id }}-{{ $option['id'] }}"><span data-picker-name>{{ $option['label'] }}</span>@if(! empty($option['sub']))<span class="text-secondary small"> &middot; {{ $option['sub'] }}</span>@endif</label></div>
@endforeach
</div>
