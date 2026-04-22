@extends('layouts.app')
@section('title','New Leave Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.requests.index') }}">Leave Requests</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-calendar-minus me-2"></i>New Leave Request</div>
    <div class="card-body">
        <form method="POST" action="{{ route('leave.requests.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select @error('employee_id') is-invalid @enderror" required>
                        <option value="">— Select Employee —</option>
                        @foreach($employees as $emp)
                            <option value="{{ $emp->id }}" {{ old('employee_id') == $emp->id ? 'selected' : '' }}>
                                {{ $emp->full_name }} ({{ $emp->employee_no }})
                            </option>
                        @endforeach
                    </select>
                    @error('employee_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                    <select name="leave_type_id" class="form-select @error('leave_type_id') is-invalid @enderror" required>
                        <option value="">— Select Type —</option>
                        @foreach($leaveTypes as $lt)
                            <option value="{{ $lt->id }}" {{ old('leave_type_id') == $lt->id ? 'selected' : '' }}>
                                {{ $lt->name }} (max {{ $lt->max_days }} days)
                            </option>
                        @endforeach
                    </select>
                    @error('leave_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Start Date <span class="text-danger">*</span></label>
                    <input type="date" name="start_date" class="form-control" value="{{ old('start_date') }}" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">End Date <span class="text-danger">*</span></label>
                    <input type="date" name="end_date" class="form-control" value="{{ old('end_date') }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Days <span class="text-danger">*</span></label>
                    <input type="number" name="days_requested" class="form-control" min="1" value="{{ old('days_requested', 1) }}" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Leave Year <span class="text-danger">*</span></label>
                    <input type="text" name="leave_year" class="form-control" value="{{ old('leave_year', now()->year) }}" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Phone During Leave</label>
                    <input type="text" name="leave_phone" class="form-control" value="{{ old('leave_phone') }}">
                </div>
                <div class="col-md-6">
                    <label class="form-label">Address During Leave</label>
                    <input type="text" name="leave_address" class="form-control" value="{{ old('leave_address') }}">
                </div>
                <div class="col-12">
                    <label class="form-label">Description / Reason</label>
                    <textarea name="description" class="form-control" rows="2">{{ old('description') }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Submit</button>
                <a href="{{ route('leave.requests.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
