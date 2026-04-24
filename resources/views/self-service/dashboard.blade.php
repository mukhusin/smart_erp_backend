@extends('layouts.app')
@section('title', 'My Self-Service')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Self-Service</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="mb-0">Welcome, {{ $employee->full_name }}</h4>
        <small class="text-muted">{{ $employee->employee_no }} &middot; {{ $employee->jobPosition?->title ?? 'N/A' }} &middot; {{ $employee->department?->name }}</small>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('self-service.profile') }}" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-person me-1"></i>My Profile
        </a>
        <a href="{{ route('self-service.request-leave') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-calendar-plus me-1"></i>Request Leave
        </a>
    </div>
</div>

{{-- Stat Cards --}}
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-warning bg-opacity-10 p-3">
                    <i class="bi bi-hourglass-split text-warning fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Pending Leaves</div>
                    <div class="fs-4 fw-bold">{{ $pendingLeaves }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-success bg-opacity-10 p-3">
                    <i class="bi bi-calendar-check text-success fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Currently On Leave</div>
                    <div class="fs-4 fw-bold">{{ $onLeaveNow }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-info bg-opacity-10 p-3">
                    <i class="bi bi-list-check text-info fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Leaves Taken</div>
                    <div class="fs-4 fw-bold">{{ $totalLeaves }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-primary bg-opacity-10 p-3">
                    <i class="bi bi-cash-stack text-primary fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Last Payslip Net</div>
                    <div class="fs-5 fw-bold">
                        @if($latestPayslip)
                            {{ number_format($latestPayslip->net_salary, 0) }}
                        @else
                            N/A
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- Quick Navigation --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-bottom">
                <h6 class="mb-0"><i class="bi bi-grid me-2 text-primary"></i>Quick Access</h6>
            </div>
            <div class="list-group list-group-flush">
                <a href="{{ route('self-service.my-leaves') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-calendar-x text-danger fs-5"></i>
                    <div>
                        <div class="fw-medium">My Leave Requests</div>
                        <small class="text-muted">View history &amp; status</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
                <a href="{{ route('self-service.request-leave') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-calendar-plus text-success fs-5"></i>
                    <div>
                        <div class="fw-medium">Request Leave</div>
                        <small class="text-muted">Submit a new leave request</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
                <a href="{{ route('self-service.duty-roster') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-calendar3-week text-info fs-5"></i>
                    <div>
                        <div class="fw-medium">My Duty Roster</div>
                        <small class="text-muted">View assigned shifts</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
                <a href="{{ route('self-service.payslip') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-receipt text-warning fs-5"></i>
                    <div>
                        <div class="fw-medium">My Payslips</div>
                        <small class="text-muted">Download salary slips</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
                <a href="{{ route('self-service.profile') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-person-badge text-secondary fs-5"></i>
                    <div>
                        <div class="fw-medium">My Profile</div>
                        <small class="text-muted">Personal &amp; employment info</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- Recent Leave Requests --}}
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Leave Requests</h6>
                <a href="{{ route('self-service.my-leaves') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                @forelse($recentLeaves as $leave)
                    <div class="d-flex align-items-center gap-3 px-3 py-3 border-bottom">
                        <div class="rounded-circle bg-primary bg-opacity-10 p-2">
                            <i class="bi bi-calendar-event text-primary"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-medium">{{ $leave->leaveType?->name ?? 'Leave' }}</div>
                            <small class="text-muted">
                                {{ \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') }}
                                – {{ \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') }}
                                ({{ $leave->days_requested }} days)
                            </small>
                        </div>
                        @php
                            $badgeClass = match($leave->status) {
                                'approved'     => 'bg-success',
                                'pending'      => 'bg-warning text-dark',
                                'hod_approved' => 'bg-info',
                                'rejected'     => 'bg-danger',
                                default        => 'bg-secondary',
                            };
                        @endphp
                        <span class="badge {{ $badgeClass }}">{{ ucfirst(str_replace('_', ' ', $leave->status)) }}</span>
                    </div>
                @empty
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-calendar2 fs-3 d-block mb-2"></i>
                        No leave requests yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Employment Summary --}}
<div class="card border-0 shadow-sm mt-3">
    <div class="card-header bg-transparent border-bottom">
        <h6 class="mb-0"><i class="bi bi-briefcase me-2 text-primary"></i>Employment Summary</h6>
    </div>
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <div class="text-muted small">Employee No</div>
                <div class="fw-medium">{{ $employee->employee_no }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Department</div>
                <div class="fw-medium">{{ $employee->department?->name ?? '—' }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Job Position</div>
                <div class="fw-medium">{{ $employee->jobPosition?->title ?? '—' }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Employment Status</div>
                <span class="badge bg-{{ $employee->employment_status === 'active' ? 'success' : 'secondary' }}">
                    {{ ucfirst($employee->employment_status) }}
                </span>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Contract Type</div>
                <div class="fw-medium">{{ $employee->activeContract?->natureOfContract?->name ?? '—' }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Contract Start</div>
                <div class="fw-medium">{{ $employee->activeContract?->start_date?->format('d M Y') ?? '—' }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Contract End</div>
                <div class="fw-medium">{{ $employee->activeContract?->end_date?->format('d M Y') ?? 'Open-ended' }}</div>
            </div>
            <div class="col-md-3">
                <div class="text-muted small">Employment Date</div>
                <div class="fw-medium">{{ $employee->employment_date?->format('d M Y') ?? '—' }}</div>
            </div>
        </div>
    </div>
</div>
@endsection
