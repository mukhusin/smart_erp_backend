<div class="row g-3">
    <div class="col-md-4"><label class="form-label">Code *</label><input type="text" name="code" class="form-control" value="{{ old('code', $code->code ?? '') }}" required maxlength="10"></div>
    <div class="col-md-8"><label class="form-label">Duty Name *</label><input type="text" name="duty" class="form-control" value="{{ old('duty', $code->duty ?? '') }}" required></div>
    <div class="col-md-4"><label class="form-label">Start Time</label><input type="time" name="start_time" class="form-control" value="{{ old('start_time', $code->start_time ?? '') }}"></div>
    <div class="col-md-4"><label class="form-label">End Time</label><input type="time" name="end_time" class="form-control" value="{{ old('end_time', $code->end_time ?? '') }}"></div>
    <div class="col-md-4"><label class="form-label">Work Hours</label><input type="number" name="work_hour" step="0.5" class="form-control" value="{{ old('work_hour', $code->work_hour ?? '') }}"></div>
    <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2">{{ old('description', $code->description ?? '') }}</textarea></div>
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="can_punch" value="1" {{ old('can_punch', ($code->can_punch ?? false) ? '1' : '') ? 'checked' : '' }}><label class="form-check-label">Can Punch</label></div></div>
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="pass_day" value="1" {{ old('pass_day', ($code->pass_day ?? false) ? '1' : '') ? 'checked' : '' }}><label class="form-check-label">Pass Day</label></div></div>
</div>
