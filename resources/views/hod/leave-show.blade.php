@extends('layouts.app')
@section('title', 'Review Leave Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hod.dashboard') }}">HOD</a></li>
    <li class="breadcrumb-item"><a href="{{ route('hod.leaves') }}">Leaves</a></li>
    <li class="breadcrumb-item active">Review</li>
@endsection

@section('content')
<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm mb-3">
            <div class="card-header bg-transparent border-bottom">
                <h5 class="mb-0"><i class="bi bi-calendar-check me-2 text-primary"></i>Leave Request Review</h5>
            </div>
            <div class="card-body">
                {{-- Employee Info --}}
                <div class="d-flex align-items-center gap-3 mb-4 p-3 bg-light rounded-3">
                    <div class="rounded-circle bg-primary bg-opacity-10 p-3">
                        <i class="bi bi-person text-primary fs-4"></i>
                    </div>
                    <div>
                        <div class="fw-bold fs-6">{{ $leave->employee?->full_name }}</div>
                        <div class="text-muted small">
                            {{ $leave->employee?->employee_no }}
                            &middot; {{ $leave->employee?->jobPosition?->title ?? 'N/A' }}
                            &middot; {{ $leave->employee?->department?->name }}
                        </div>
                    </div>
                    @php $cls = match($leave->status) {
                        'approved' => 'bg-success', 'pending' => 'bg-warning text-dark',
                        'hod_approved' => 'bg-info', 'rejected' => 'bg-danger',
                        default => 'bg-secondary'
                    }; @endphp
                    <span class="badge {{ $cls }} ms-auto fs-6">{{ ucfirst(str_replace('_',' ',$leave->status)) }}</span>
                </div>

                {{-- Leave Details --}}
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

                {{-- Previous HOD/HR remarks if any --}}
                @if($leave->hod_remark)
                    <div class="alert alert-info py-2">
                        <small><strong>HOD Remark:</strong> {{ $leave->hod_remark }}</small>
                    </div>
                @endif
                @if($leave->hr_office_remark)
                    <div class="alert alert-secondary py-2">
                        <small><strong>HR Remark:</strong> {{ $leave->hr_office_remark }}</small>
                    </div>
                @endif

                @if(in_array($leave->status, ['pending', 'hod_approved']) && $leave->status !== 'rejected')
                {{-- Action Forms --}}
                <hr>
                <div class="row g-3">
                    {{-- Approve --}}
                    <div class="col-md-6">
                        <form method="POST" action="{{ route('hod.leaves.approve', $leave) }}">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label small">Approval Remark (optional)</label>
                                <input type="text" name="remark" class="form-control form-control-sm"
                                       placeholder="e.g. Approved as requested">
                            </div>
                            <button type="submit" class="btn btn-success w-100"
                                    onclick="return confirm('Approve this leave request?')">
                                <i class="bi bi-check-lg me-1"></i>Approve & Forward to HR
                            </button>
                        </form>
                    </div>
                    {{-- Reject --}}
                    <div class="col-md-6">
                        <form method="POST" action="{{ route('hod.leaves.reject', $leave) }}">
                            @csrf
                            <div class="mb-2">
                                <label class="form-label small">Rejection Reason <span class="text-danger">*</span></label>
                                <input type="text" name="remark" class="form-control form-control-sm"
                                       placeholder="Reason for rejection…" required>
                            </div>
                            <button type="submit" class="btn btn-danger w-100"
                                    onclick="return confirm('Reject this leave request?')">
                                <i class="bi bi-x-lg me-1"></i>Reject Request
                            </button>
                        </form>
                    </div>
                </div>
                @endif
            </div>
            <div class="card-footer bg-transparent d-flex justify-content-between">
                <a href="{{ route('hod.leaves') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Back
                </a>
                <small class="text-muted align-self-center">Submitted {{ $leave->created_at->format('d M Y H:i') }}</small>
            </div>
        </div>
    </div>
</div>
@endsection
