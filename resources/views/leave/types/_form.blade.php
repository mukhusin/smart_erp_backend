<div class="row g-3">
    <div class="col-md-8">
        <label class="form-label">Name <span class="text-danger">*</span></label>
        <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
               value="{{ old('name', $type->name ?? '') }}" required>
        @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label">Code <span class="text-danger">*</span></label>
        <input type="text" name="code" class="form-control @error('code') is-invalid @enderror"
               value="{{ old('code', $type->code ?? '') }}" required maxlength="20">
        @error('code')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="col-md-4">
        <label class="form-label">Applicable Gender</label>
        <select name="gender" class="form-select">
            @foreach(['all' => 'All', 'male' => 'Male Only', 'female' => 'Female Only'] as $val => $label)
                <option value="{{ $val }}" {{ old('gender', $type->gender ?? 'all') == $val ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-4">
        <label class="form-label">Max Days <span class="text-danger">*</span></label>
        <input type="number" name="max_days" class="form-control" min="1"
               value="{{ old('max_days', $type->max_days ?? 30) }}" required>
    </div>
    <div class="col-md-4 d-flex align-items-end gap-3">
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="with_pay" value="1"
                   id="withPay" {{ old('with_pay', ($type->with_pay ?? true) ? '1' : '') ? 'checked' : '' }}>
            <label class="form-check-label" for="withPay">With Pay</label>
        </div>
        @isset($type)
        <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" name="is_active" value="1"
                   id="isActive" {{ old('is_active', $type->is_active ? '1' : '') ? 'checked' : '' }}>
            <label class="form-check-label" for="isActive">Active</label>
        </div>
        @endisset
    </div>
    <div class="col-12">
        <label class="form-label">Description</label>
        <textarea name="description" class="form-control" rows="2">{{ old('description', $type->description ?? '') }}</textarea>
    </div>
</div>
