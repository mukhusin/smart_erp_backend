@extends('layouts.app')
@section('title','Leave Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.requests.index') }}">Leave Requests</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-calendar-minus me-2"></i>Leave Request</span>
        @php $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$leave->status] ?? 'secondary' @endphp
        <span class="badge bg-{{ $sc }} fs-6">{{ ucfirst($leave->status) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Employee</dt>
            <dd class="col-sm-8">{{ $leave->employee?->full_name }}</dd>
            <dt class="col-sm-4">Leave Type</dt>
            <dd class="col-sm-8">{{ $leave->leaveType?->name }}</dd>
            <dt class="col-sm-4">Period</dt>
            <dd class="col-sm-8">{{ $leave->start_date }} → {{ $leave->end_date }} ({{ $leave->days }} days)</dd>
            <dt class="col-sm-4">Leave Year</dt>
            <dd class="col-sm-8">{{ $leave->leave_year }}</dd>
            <dt class="col-sm-4">Description</dt>
            <dd class="col-sm-8">{{ $leave->description ?? '—' }}</dd>
            <dt class="col-sm-4">HOD Remark</dt>
            <dd class="col-sm-8">{{ $leave->hod_remark ?? '—' }}
                @if($leave->hod_is_approved) <span class="badge bg-success ms-2">HOD Approved</span> @endif
            </dd>
            <dt class="col-sm-4">HR Remark</dt>
            <dd class="col-sm-8">{{ $leave->hr_office_remark ?? '—' }}
                @if($leave->hr_office_is_approved) <span class="badge bg-success ms-2">HR Approved</span> @endif
            </dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('leave.requests.edit', $leave) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('leave.requests.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
</div>
</div>
@endsection
