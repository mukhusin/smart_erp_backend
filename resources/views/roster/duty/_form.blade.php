<div class="row g-3">
    <div class="col-12"><label class="form-label">Title *</label><input type="text" name="title" class="form-control" value="{{ old('title', $duty->title ?? '') }}" required></div>
    <div class="col-md-6">
        <label class="form-label">Department *</label>
        <select name="department_id" class="form-select" required>
            <option value="">— Select —</option>
            @foreach($departments as $d)<option value="{{ $d->id }}" {{ old('department_id', $duty->department_id ?? '') == $d->id ? 'selected' : '' }}>{{ $d->name }}</option>@endforeach
        </select>
    </div>
    <div class="col-md-3"><label class="form-label">From Date *</label><input type="date" name="from_date" class="form-control" value="{{ old('from_date', $duty->from_date ?? '') }}" required></div>
    <div class="col-md-3"><label class="form-label">To Date *</label><input type="date" name="to_date" class="form-control" value="{{ old('to_date', $duty->to_date ?? '') }}" required></div>
    <div class="col-md-4"><label class="form-label">Roster Year</label><input type="text" name="roster_year" class="form-control" maxlength="4" value="{{ old('roster_year', $duty->roster_year ?? now()->year) }}"></div>
    @isset($duty)
    <div class="col-md-4">
        <label class="form-label">Status</label>
        <select name="status" class="form-select">
            @foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ $duty->status==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach
        </select>
    </div>
    @endisset
</div>
