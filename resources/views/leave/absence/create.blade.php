@extends('layouts.app')
@section('title','New Absence Request')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.absence.index') }}">Absence Requests</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">New Absence Request</div>
    <div class="card-body">
        <form method="POST" action="{{ route('leave.absence.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Employee *</label>
                    <select name="employee_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($employees as $e)<option value="{{ $e->id }}" {{ old('employee_id')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-3"><label class="form-label">From Date *</label><input type="date" name="from_date" class="form-control" value="{{ old('from_date') }}" required></div>
                <div class="col-md-3"><label class="form-label">To Date *</label><input type="date" name="to_date" class="form-control" value="{{ old('to_date') }}" required></div>
                <div class="col-md-3"><label class="form-label">From Time</label><input type="time" name="from_time" class="form-control" value="{{ old('from_time') }}"></div>
                <div class="col-md-3"><label class="form-label">To Time</label><input type="time" name="to_time" class="form-control" value="{{ old('to_time') }}"></div>
                <div class="col-md-3"><label class="form-label">Number of Days *</label><input type="number" name="number_of_days" class="form-control" min="1" value="{{ old('number_of_days',1) }}" required></div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="has_per_diem" value="1" id="perDiem"><label class="form-check-label" for="perDiem">Has Per Diem</label></div>
                </div>
                <div class="col-12"><label class="form-label">Description *</label><textarea name="description" class="form-control" rows="2" required>{{ old('description') }}</textarea></div>
                <div class="col-12"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="{{ old('address') }}"></div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button class="btn btn-primary">Submit</button>
                <a href="{{ route('leave.absence.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
