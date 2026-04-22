<div class="row g-3">
    <div class="col-md-6"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" value="{{ old('title', $timetable->title ?? '') }}" required></div>
    <div class="col-md-6">
        <label class="form-label">Department *</label>
        <select name="department_id" class="form-select" required>
            <option value="">— Select —</option>
            @foreach($departments as $d)<option value="{{ $d->id }}" {{ old('department_id', $timetable->department_id ?? '') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3"><label class="form-label">Check-In Start *</label><input type="time" name="check_in_start" class="form-control" value="{{ old('check_in_start', $timetable->check_in_start ?? '') }}" required></div>
    <div class="col-md-3"><label class="form-label">Check-In End *</label><input type="time" name="check_in_end" class="form-control" value="{{ old('check_in_end', $timetable->check_in_end ?? '') }}" required></div>
    <div class="col-md-3"><label class="form-label">Check-Out Start *</label><input type="time" name="check_out_start" class="form-control" value="{{ old('check_out_start', $timetable->check_out_start ?? '') }}" required></div>
    <div class="col-md-3"><label class="form-label">Check-Out End *</label><input type="time" name="check_out_end" class="form-control" value="{{ old('check_out_end', $timetable->check_out_end ?? '') }}" required></div>
    <div class="col-md-4"><label class="form-label">Work Hours *</label><input type="text" name="work_hour" class="form-control" value="{{ old('work_hour', $timetable->work_hour ?? '8') }}" required></div>
    <div class="col-md-4"><label class="form-label">Cross Day (hrs)</label><input type="number" name="cross_day" class="form-control" value="{{ old('cross_day', $timetable->cross_day ?? 0) }}" min="0"></div>
    <div class="col-md-4 d-flex align-items-end">
        <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="use_attendance" value="1" {{ old('use_attendance', ($timetable->use_attendance ?? true) ? '1' : '') ? 'checked' : '' }}><label class="form-check-label">Use for Attendance</label></div>
    </div>
    @isset($timetable)
    <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" value="1" {{ $timetable->is_active ? 'checked' : '' }}><label class="form-check-label">Active</label></div></div>
    @endisset
</div>
