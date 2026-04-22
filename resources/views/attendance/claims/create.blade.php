@extends('layouts.app')
@section('title','New Attendance Claim')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('attendance.claims.index') }}">Claims</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">New Attendance Claim</div>
    <div class="card-body">
        <form method="POST" action="{{ route('attendance.claims.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-12">
                    <label class="form-label">Attendance Record *</label>
                    <select name="attendance_transa_id" class="form-select" required>
                        <option value="">— Select Record —</option>
                        @foreach($transactions as $t)<option value="{{ $t->id }}" {{ old('attendance_transa_id')==$t->id?'selected':'' }}>{{ $t->employee?->full_name }} — {{ $t->transaction_date }} (lost: {{ $t->lost_hour }}h)</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Reason *</label>
                    <select name="claim_reason_id" class="form-select" required>
                        <option value="">— Select —</option>
                        @foreach($reasons as $r)<option value="{{ $r->id }}" {{ old('claim_reason_id')==$r->id?'selected':'' }}>{{ $r->reason }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">Claim Date *</label><input type="date" name="claim_date" class="form-control" value="{{ old('claim_date', now()->toDateString()) }}" required></div>
                <div class="col-12"><label class="form-label">Description *</label><textarea name="description" class="form-control" rows="2" required>{{ old('description') }}</textarea></div>
                <div class="col-12"><label class="form-label">Remark</label><input type="text" name="remark" class="form-control" value="{{ old('remark') }}"></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Submit</button><a href="{{ route('attendance.claims.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
