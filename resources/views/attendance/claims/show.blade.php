@extends('layouts.app')
@section('title','Attendance Claim')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('attendance.claims.index') }}">Claims</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between">
        <span class="fw-semibold">Attendance Claim</span>
        @php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$claim->claim_status]??'secondary'@endphp
        <span class="badge bg-{{ $sc }}">{{ ucfirst($claim->claim_status) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Employee</dt><dd class="col-sm-8">{{ $claim->employee?->full_name }}</dd>
            <dt class="col-sm-4">Claim Date</dt><dd class="col-sm-8">{{ $claim->claim_date }}</dd>
            <dt class="col-sm-4">Reason</dt><dd class="col-sm-8">{{ $claim->reason?->reason }}</dd>
            <dt class="col-sm-4">Lost Hours</dt><dd class="col-sm-8">{{ $claim->lost_hour }}</dd>
            <dt class="col-sm-4">Lost Amount</dt><dd class="col-sm-8">{{ number_format($claim->lost_amount, 2) }}</dd>
            <dt class="col-sm-4">Description</dt><dd class="col-sm-8">{{ $claim->description }}</dd>
            <dt class="col-sm-4">Comment</dt><dd class="col-sm-8">{{ $claim->comment ?? '—' }}</dd>
            <dt class="col-sm-4">Approved By</dt><dd class="col-sm-8">{{ $claim->approvedBy?->full_name ?? '—' }}</dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('attendance.claims.edit', $claim) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('attendance.claims.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
</div></div>
@endsection
