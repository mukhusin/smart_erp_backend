@extends('layouts.app')
@section('title','New Annual Leave Entry')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.annual-leave.index') }}">Annual Leave</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">New Annual Leave Roster Entry</div>
    <div class="card-body">
        <form method="POST" action="{{ route('roster.annual-leave.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Roster *</label>
                    <select name="roster_id" class="form-select" required>
                        <option value="">— Select Roster —</option>
                        @foreach($rosters as $r)<option value="{{ $r->id }}" {{ old('roster_id')==$r->id?'selected':'' }}>{{ $r->title }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Employee *</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($employees as $e)<option value="{{ $e->id }}" {{ old('employee_id')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-4"><label class="form-label">Start Date *</label><input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}" required></div>
                <div class="col-md-4"><label class="form-label">End Date *</label><input type="date" name="end_date" class="form-control" value="{{ old('end_date') }}" required></div>
                <div class="col-md-2"><label class="form-label">Days *</label><input type="number" name="days" class="form-control" min="1" value="{{ old('days',1) }}" required></div>
                <div class="col-md-2"><label class="form-label">Year</label><input type="text" name="leave_year" class="form-control" value="{{ old('leave_year',now()->year) }}"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="description" class="form-control" rows="2">{{ old('description') }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Save</button><a href="{{ route('roster.annual-leave.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
