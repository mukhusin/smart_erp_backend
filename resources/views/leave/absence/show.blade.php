@extends('layouts.app')
@section('title','Absence Request')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.absence.index') }}">Absence Requests</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between"><span class="fw-semibold">Absence Request</span>
        @php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$absence->status]??'secondary' @endphp
        <span class="badge bg-{{ $sc }}">{{ ucfirst($absence->status) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Employee</dt><dd class="col-sm-8">{{ $absence->employee?->full_name }}</dd>
            <dt class="col-sm-4">Period</dt><dd class="col-sm-8">{{ $absence->from_date?->format('Y-m-d') }} → {{ $absence->to_date?->format('Y-m-d') }} ({{ $absence->number_of_days }} days)</dd>
            <dt class="col-sm-4">Time</dt><dd class="col-sm-8">{{ $absence->from_time ?? '—' }} — {{ $absence->to_time ?? '—' }}</dd>
            <dt class="col-sm-4">Per Diem</dt><dd class="col-sm-8">{{ $absence->has_per_diem ? 'Yes' : 'No' }}</dd>
            <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $absence->description }}</dd>
            <dt class="col-sm-4">Address</dt><dd class="col-sm-8">{{ $absence->address ?? '—' }}</dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('leave.absence.edit', $absence) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('leave.absence.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
</div></div>
@endsection
