@extends('layouts.app')
@section('title','Edit Leave Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.requests.index') }}">Leave Requests</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit Leave Request — {{ $leave->employee?->full_name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('leave.requests.update', $leave) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Leave Type</label>
                    <select name="leave_type_id" class="form-select" required>
                        @foreach($leaveTypes as $lt)
                            <option value="{{ $lt->id }}" {{ $leave->leave_type_id == $lt->id ? 'selected' : '' }}>{{ $lt->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Start Date</label>
                    <input type="date" name="start_date" class="form-control" value="{{ $leave->start_date }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">End Date</label>
                    <input type="date" name="end_date" class="form-control" value="{{ $leave->end_date }}" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Days</label>
                    <input type="number" name="days_requested" class="form-control" value="{{ $leave->days_requested }}" required min="1">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Leave Year</label>
                    <input type="text" name="leave_year" class="form-control" value="{{ $leave->leave_year }}" required>
                </div>
                <div class="col-12">
                    <label class="form-label">Description</label>
                    <textarea name="description" class="form-control" rows="2">{{ $leave->description }}</textarea>
                </div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Update</button>
                <a href="{{ route('leave.requests.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
