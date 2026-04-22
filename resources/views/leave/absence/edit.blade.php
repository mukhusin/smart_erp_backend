@extends('layouts.app')
@section('title','Edit Absence Request')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.absence.index') }}">Absence Requests</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit Absence — {{ $absence->employee?->full_name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('leave.absence.update', $absence) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">From Date</label><input type="date" name="from_date" class="form-control" value="{{ $absence->from_date?->format('Y-m-d') }}" required></div>
                <div class="col-md-4"><label class="form-label">To Date</label><input type="date" name="to_date" class="form-control" value="{{ $absence->to_date?->format('Y-m-d') }}" required></div>
                <div class="col-md-4"><label class="form-label">Number of Days</label><input type="number" name="number_of_days" class="form-control" value="{{ $absence->number_of_days }}" required min="1"></div>
                <div class="col-md-4"><label class="form-label">From Time</label><input type="time" name="from_time" class="form-control" value="{{ $absence->from_time }}"></div>
                <div class="col-md-4"><label class="form-label">To Time</label><input type="time" name="to_time" class="form-control" value="{{ $absence->to_time }}"></div>
                <div class="col-md-4">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        @foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ $absence->status==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach
                    </select>
                </div>
                <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" required>{{ $absence->description }}</textarea></div>
                <div class="col-12"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="{{ $absence->address }}"></div>
                <div class="col-auto"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="has_per_diem" value="1" {{ $absence->has_per_diem?'checked':'' }}><label class="form-check-label">Has Per Diem</label></div></div>
            </div>
            <div class="d-flex gap-2 mt-3">
                <button class="btn btn-primary">Update</button>
                <a href="{{ route('leave.absence.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div></div>
@endsection
