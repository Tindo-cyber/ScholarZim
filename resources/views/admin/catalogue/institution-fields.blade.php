{{-- One institution's fields, for both "add" ($institution null) and "edit". --}}
<form method="POST" action="{{ $action }}" class="row g-2" novalidate>
    @csrf
    <div class="col-md-2">
        <label class="form-label small" for="{{ $prefix }}-code">Code</label>
        <input class="form-control form-control-sm" id="{{ $prefix }}-code" name="code" maxlength="30" value="{{ $institution ? $institution->code : old('code') }}">
    </div>
    <div class="col-md-4">
        <label class="form-label small" for="{{ $prefix }}-name">Name</label>
        <input class="form-control form-control-sm" id="{{ $prefix }}-name" name="name" maxlength="200" value="{{ $institution ? $institution->name : old('name') }}">
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="{{ $prefix }}-type">Type</label>
        <select class="form-select form-select-sm" id="{{ $prefix }}-type" name="type">
            @foreach($types as $value => $label)
                <option value="{{ $value }}" @selected(($institution ? $institution->type : old('type')) === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label small" for="{{ $prefix }}-province">Province</label>
        <select class="form-select form-select-sm" id="{{ $prefix }}-province" name="province">
            <option value="">-</option>
            @foreach($provinces as $province)
                <option value="{{ $province }}" @selected(($institution ? $institution->province : old('province')) === $province)>{{ $province }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
        <div class="form-check">
            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="{{ $prefix }}-active" @checked($institution ? $institution->is_active : true)>
            <label class="form-check-label small" for="{{ $prefix }}-active">On</label>
        </div>
    </div>
    <div class="col-md-1 d-flex align-items-end"><button class="btn btn-sm btn-primary w-100" type="submit">{{ $button }}</button></div>
    @if($errors->any() && ! $institution)
        <div class="col-12 text-danger small">{{ $errors->first() }}</div>
    @endif
</form>
