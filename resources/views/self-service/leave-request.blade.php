@extends('layouts.app')
@section('title', 'Request Leave')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('self-service.dashboard') }}">Self-Service</a></li>
    <li class="breadcrumb-item active">Request Leave</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0"><i class="bi bi-calendar-plus me-2 text-primary"></i>New Leave Request</h5>
                <small class="text-muted">{{ $employee->full_name }} &middot; {{ $employee->department?->name }}</small>
            </div>
            <div class="card-body">
                <form method="POST" action="{{ route('self-service.submit-leave') }}">
                    @csrf

                    <div class="mb-3">
                        <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                        <select name="leave_type_id" class="form-select @error('leave_type_id') is-invalid @enderror" required>
                            <option value="">— Select Leave Type —</option>
                            @foreach($leaveTypes as $type)
                                <option value="{{ $type->id }}" {{ old('leave_type_id') == $type->id ? 'selected' : '' }}>
                                    {{ $type->name }}
                                    @if($type->max_days) (max {{ $type->max_days }} days) @endif
                                    {{ $type->with_pay ? '— With Pay' : '— Without Pay' }}
                                </option>
                            @endforeach
                        </select>
                        @error('leave_type_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start Date <span class="text-danger">*</span></label>
                            <input type="date" name="start_date" class="form-control @error('start_date') is-invalid @enderror"
                                   value="{{ old('start_date') }}" min="{{ date('Y-m-d') }}" required id="startDate">
                            @error('start_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End Date <span class="text-danger">*</span></label>
                            <input type="date" name="end_date" class="form-control @error('end_date') is-invalid @enderror"
                                   value="{{ old('end_date') }}" min="{{ date('Y-m-d') }}" required id="endDate">
                            @error('end_date')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Number of Days <span class="text-danger">*</span></label>
                        <input type="number" name="days_requested" id="daysRequested"
                               class="form-control @error('days_requested') is-invalid @enderror"
                               value="{{ old('days_requested', 1) }}" min="1" required>
                        @error('days_requested')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <div class="form-text">Auto-calculated from dates. You may adjust for partial days.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Reason / Description</label>
                        <textarea name="description" rows="3"
                                  class="form-control @error('description') is-invalid @enderror"
                                  placeholder="Briefly describe the reason for leave…">{{ old('description') }}</textarea>
                        @error('description')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Contact Phone During Leave</label>
                            <input type="text" name="leave_phone"
                                   class="form-control @error('leave_phone') is-invalid @enderror"
                                   value="{{ old('leave_phone', $employee->phone_number) }}">
                            @error('leave_phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Address During Leave</label>
                            <input type="text" name="leave_address"
                                   class="form-control @error('leave_address') is-invalid @enderror"
                                   value="{{ old('leave_address', $employee->home_address) }}">
                            @error('leave_address')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    <div class="alert alert-info d-flex align-items-center gap-2 py-2">
                        <i class="bi bi-info-circle"></i>
                        <small>Your request will be reviewed by your HOD, then HR. You will be notified of the decision.</small>
                    </div>

                    <div class="d-flex gap-2 justify-content-end">
                        <a href="{{ route('self-service.my-leaves') }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-send me-1"></i>Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function calcDays() {
        const s = document.getElementById('startDate').value;
        const e = document.getElementById('endDate').value;
        if (s && e) {
            const diff = (new Date(e) - new Date(s)) / (1000 * 60 * 60 * 24) + 1;
            if (diff > 0) document.getElementById('daysRequested').value = diff;
        }
    }
    document.getElementById('startDate').addEventListener('change', calcDays);
    document.getElementById('endDate').addEventListener('change', calcDays);
</script>
@endpush
