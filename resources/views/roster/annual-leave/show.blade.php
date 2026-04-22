@extends('layouts.app')
@section('title','Annual Leave Entry')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.annual-leave.index') }}">Annual Leave</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Annual Leave — {{ $annualLeave->employee?->full_name }}</div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Roster</dt><dd class="col-sm-8">{{ $annualLeave->roster?->title }}</dd>
            <dt class="col-sm-4">Period</dt><dd class="col-sm-8">{{ $annualLeave->start_date }} → {{ $annualLeave->end_date }} ({{ $annualLeave->days }} days)</dd>
            <dt class="col-sm-4">Leave Year</dt><dd class="col-sm-8">{{ $annualLeave->leave_year }}</dd>
            <dt class="col-sm-4">Status</dt><dd class="col-sm-8">{{ str_replace('_',' ',ucfirst($annualLeave->status)) }}</dd>
            <dt class="col-sm-4">HOD</dt><dd class="col-sm-8">{{ $annualLeave->hod?->full_name ?? '—' }} {{ $annualLeave->hod_is_approved ? '<span class="badge bg-success ms-1">Approved</span>' : '' }}</dd>
            <dt class="col-sm-4">HR</dt><dd class="col-sm-8">{{ $annualLeave->hr?->full_name ?? '—' }} {{ $annualLeave->hr_office_is_approved ? '<span class="badge bg-success ms-1">Approved</span>' : '' }}</dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('roster.annual-leave.edit', $annualLeave) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('roster.annual-leave.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
</div></div>
@endsection
