@extends('layouts.app')
@section('title','Review Claim')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('attendance.claims.index') }}">Claims</a></li>
    <li class="breadcrumb-item active">Review</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Review Claim — {{ $claim->employee?->full_name }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('attendance.claims.update', $claim) }}">
            @csrf @method('PUT')
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Status *</label>
                    <select name="claim_status" class="form-select" required>
                        @foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ $claim->claim_status==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">HR Accepted Hours</label><input type="number" name="hr_accepted" class="form-control" step="0.01" value="{{ $claim->hr_accepted }}"></div>
                <div class="col-12"><label class="form-label">Comment</label><textarea name="comment" class="form-control" rows="2">{{ $claim->comment }}</textarea></div>
            </div>
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('attendance.claims.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
