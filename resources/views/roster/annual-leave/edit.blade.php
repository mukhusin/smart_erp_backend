@extends('layouts.app')
@section('title','Edit Annual Leave')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.annual-leave.index') }}">Annual Leave</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit: {{ $annualLeave->employee?->full_name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('roster.annual-leave.update', $annualLeave) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Start Date</label><input type="date" name="start_date" class="form-control" value="{{ $annualLeave->start_date }}" required></div>
                <div class="col-md-4"><label class="form-label">End Date</label><input type="date" name="end_date" class="form-control" value="{{ $annualLeave->end_date }}" required></div>
                <div class="col-md-4"><label class="form-label">Days</label><input type="number" name="days" class="form-control" value="{{ $annualLeave->days }}" required min="1"></div>
                <div class="col-12"><label class="form-label">Notes</label><textarea name="description" class="form-control" rows="2">{{ $annualLeave->description }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('roster.annual-leave.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
