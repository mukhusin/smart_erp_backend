<div class="row g-3">
    <div class="col-md-6"><label class="form-label">Category Name *</label><input type="text" name="category" class="form-control" value="{{ old('category', $category->category ?? '') }}" required></div>
    <div class="col-md-3"><label class="form-label">Code *</label><input type="text" name="code" class="form-control" value="{{ old('code', $category->code ?? '') }}" required maxlength="20"></div>
    <div class="col-md-3">
        <label class="form-label">Duration *</label>
        <select name="duration" class="form-select" required>
            <option value="month" {{ old('duration', $category->duration ?? '') == 'month' ? 'selected' : '' }}>Monthly</option>
            <option value="day" {{ old('duration', $category->duration ?? '') == 'day' ? 'selected' : '' }}>Daily</option>
        </select>
    </div>
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="claimed" value="1" {{ old('claimed', ($category->claimed ?? false) ? '1' : '') ? 'checked' : '' }}><label class="form-check-label">Claimed</label></div></div>
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="deduct_leave" value="1" {{ old('deduct_leave', ($category->deduct_leave ?? true) ? '1' : '') ? 'checked' : '' }}><label class="form-check-label">Deduct on Leave</label></div></div>
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="other_absence" value="1" {{ old('other_absence', ($category->other_absence ?? false) ? '1' : '') ? 'checked' : '' }}><label class="form-check-label">Other Absence</label></div></div>
    @isset($category)
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $category->is_active ? 'checked' : '' }}><label class="form-check-label">Active</label></div></div>
    @endisset
</div>
