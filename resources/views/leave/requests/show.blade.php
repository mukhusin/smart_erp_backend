@extends('layouts.app')
@section('title','Leave Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.requests.index') }}">Leave Requests</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">

    <div class="card border-0 shadow-sm mb-3">
        <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
            <h5 class="mb-0"><i class="bi bi-calendar-check me-2 text-primary"></i>Leave Request Details</h5>
            @php
                $cls = match($leave->status) {
                    'approved' => 'bg-success', 'pending' => 'bg-warning text-dark',
                    'hod_approved' => 'bg-info', 'rejected' => 'bg-danger',
                    'active' => 'bg-primary', default => 'bg-secondary'
                };
            @endphp
            <span class="badge {{ $cls }} fs-6">{{ ucfirst(str_replace('_', ' ', $leave->status)) }}</span>
        </div>

        <div class="card-body">
            {{-- Employee card --}}
            <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3 mb-4">
                <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                    <i class="bi bi-person text-primary fs-4"></i>
                </div>
                <div>
                    <div class="fw-bold">{{ $leave->employee?->full_name }}</div>
                    <div class="text-muted small">
                        {{ $leave->employee?->employee_no }}
                        &middot; {{ $leave->employee?->department?->name }}
                        &middot; {{ $leave->employee?->jobPosition?->title ?? 'N/A' }}
                    </div>
                </div>
            </div>

            {{-- Leave details --}}
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="text-muted small">Leave Type</div>
                    <div class="fw-medium">{{ $leave->leaveType?->name ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Start Date</div>
                    <div class="fw-medium">{{ \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">End Date</div>
                    <div class="fw-medium">{{ \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Days Requested</div>
                    <div class="fw-bold fs-5">{{ $leave->days_requested }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Leave Year</div>
                    <div class="fw-medium">{{ $leave->leave_year }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Contact Phone</div>
                    <div class="fw-medium">{{ $leave->leave_phone ?? '—' }}</div>
                </div>
                <div class="col-md-3">
                    <div class="text-muted small">Leave Address</div>
                    <div class="fw-medium">{{ $leave->leave_address ?? '—' }}</div>
                </div>
                @if($leave->description)
                <div class="col-12">
                    <div class="text-muted small">Reason</div>
                    <div>{{ $leave->description }}</div>
                </div>
                @endif
            </div>

            {{-- Approval trail --}}
            <h6 class="text-muted small text-uppercase mb-3">Approval Trail</h6>
            <div class="row g-3 mb-4">
                <div class="col-md-6">
                    <div class="border rounded p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            @if($leave->hod_is_approved)
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <span class="fw-medium text-success">HOD Approved</span>
                            @elseif($leave->hod_is_approved === false && $leave->hod_id)
                                <i class="bi bi-x-circle-fill text-danger"></i>
                                <span class="fw-medium text-danger">HOD Rejected</span>
                            @else
                                <i class="bi bi-hourglass text-warning"></i>
                                <span class="fw-medium text-muted">Awaiting HOD</span>
                            @endif
                        </div>
                        @if($leave->hod) <small class="text-muted">By: {{ $leave->hod?->full_name }}</small><br> @endif
                        @if($leave->hod_remark) <small>Remark: {{ $leave->hod_remark }}</small> @endif
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="border rounded p-3">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            @if($leave->hr_office_is_approved)
                                <i class="bi bi-check-circle-fill text-success"></i>
                                <span class="fw-medium text-success">HR Approved</span>
                            @elseif($leave->hr_office_is_approved === false && $leave->hr_id)
                                <i class="bi bi-x-circle-fill text-danger"></i>
                                <span class="fw-medium text-danger">HR Rejected</span>
                            @else
                                <i class="bi bi-hourglass text-muted"></i>
                                <span class="fw-medium text-muted">Awaiting HR</span>
                            @endif
                        </div>
                        @if($leave->hrOfficer) <small class="text-muted">By: {{ $leave->hrOfficer?->full_name }}</small><br> @endif
                        @if($leave->hr_office_remark) <small>Remark: {{ $leave->hr_office_remark }}</small> @endif
                    </div>
                </div>
            </div>

            {{-- HR Action Forms --}}
            @if(in_array($leave->status, ['pending','hod_approved']))
            <hr>
            <h6 class="text-muted small text-uppercase mb-3">HR Decision</h6>
            <div class="row g-3">
                <div class="col-md-6">
                    <form method="POST" action="{{ route('leave.requests.approve', $leave) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small">Approval Remark (optional)</label>
                            <input type="text" name="remark" class="form-control form-control-sm"
                                   placeholder="e.g. Approved as requested">
                        </div>
                        <button type="submit" class="btn btn-success w-100"
                                onclick="return confirm('Approve this leave?')">
                            <i class="bi bi-check-lg me-1"></i>Approve Leave
                        </button>
                    </form>
                </div>
                <div class="col-md-6">
                    <form method="POST" action="{{ route('leave.requests.reject', $leave) }}">
                        @csrf
                        <div class="mb-2">
                            <label class="form-label small">Rejection Reason <span class="text-danger">*</span></label>
                            <input type="text" name="remark" class="form-control form-control-sm"
                                   placeholder="Reason for rejection…" required>
                        </div>
                        <button type="submit" class="btn btn-danger w-100"
                                onclick="return confirm('Reject this leave?')">
                            <i class="bi bi-x-lg me-1"></i>Reject Leave
                        </button>
                    </form>
                </div>
            </div>
            @endif
        </div>

        <div class="card-footer bg-transparent d-flex justify-content-between">
            <a href="{{ route('leave.requests.index') }}" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-arrow-left me-1"></i>Back
            </a>
            <small class="text-muted align-self-center">Submitted {{ $leave->created_at->format('d M Y H:i') }}</small>
        </div>
    </div>

</div>
</div>
@endsection
