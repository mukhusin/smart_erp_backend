@extends('layouts.app')
@section('title','Duty Roster')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.duty.index') }}">Duty Rosters</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection
@section('content')
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between">
        <span class="fw-semibold">{{ $duty->title }}</span>
        @php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$duty->status]??'secondary'@endphp
        <span class="badge bg-{{ $sc }} fs-6">{{ ucfirst($duty->status) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-3">Department</dt><dd class="col-sm-9">{{ $duty->department?->name }}</dd>
            <dt class="col-sm-3">Period</dt><dd class="col-sm-9">{{ $duty->from_date }} → {{ $duty->to_date }}</dd>
            <dt class="col-sm-3">Year</dt><dd class="col-sm-9">{{ $duty->roster_year ?? '—' }}</dd>
            <dt class="col-sm-3">Prepared By</dt><dd class="col-sm-9">{{ $duty->preparedBy?->full_name ?? '—' }}</dd>
            <dt class="col-sm-3">Approved By</dt><dd class="col-sm-9">{{ $duty->approvedBy?->full_name ?? '—' }}</dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('roster.duty.edit', $duty) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('roster.duty.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
@endsection
