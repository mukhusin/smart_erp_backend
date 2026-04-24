@extends('layouts.app')
@section('title', 'HOD Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item active">HOD Dashboard</li>
@endsection

@section('content')
<div class="mb-4">
    <h4 class="mb-0">Department Management</h4>
    <small class="text-muted">Head of Department view</small>
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
                    <div class="text-muted small">Pending Leave Requests</div>
                    <div class="fs-3 fw-bold">{{ $pendingLeaves }}</div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <a href="{{ route('hod.leaves') }}" class="small text-primary">Review requests &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-info bg-opacity-10 p-3">
                    <i class="bi bi-send text-info fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Forwarded to HR</div>
                    <div class="fs-3 fw-bold">{{ $hodApproved }}</div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <a href="{{ route('hod.leaves', ['status' => 'hod_approved']) }}" class="small text-primary">View &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-success bg-opacity-10 p-3">
                    <i class="bi bi-people text-success fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Active Employees</div>
                    <div class="fs-3 fw-bold">{{ $deptEmployees }}</div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <a href="{{ route('hod.employees') }}" class="small text-primary">View employees &rarr;</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded-3 bg-danger bg-opacity-10 p-3">
                    <i class="bi bi-person-slash text-danger fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Currently On Leave</div>
                    <div class="fs-3 fw-bold">{{ $onLeave }}</div>
                </div>
            </div>
            <div class="card-footer bg-transparent border-top-0 pt-0">
                <a href="{{ route('hod.leaves', ['status' => 'active']) }}" class="small text-primary">View &rarr;</a>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    {{-- Quick Links --}}
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-bottom">
                <h6 class="mb-0"><i class="bi bi-grid me-2 text-primary"></i>Quick Actions</h6>
            </div>
            <div class="list-group list-group-flush">
                <a href="{{ route('hod.leaves') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-calendar-x text-warning fs-5"></i>
                    <div>
                        <div class="fw-medium">Review Leave Requests</div>
                        <small class="text-muted">Approve or reject pending requests</small>
                    </div>
                    @if($pendingLeaves > 0)
                        <span class="badge bg-warning text-dark ms-auto">{{ $pendingLeaves }}</span>
                    @else
                        <i class="bi bi-chevron-right ms-auto text-muted"></i>
                    @endif
                </a>
                <a href="{{ route('hod.annual-leaves') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-calendar2-heart text-info fs-5"></i>
                    <div>
                        <div class="fw-medium">Annual Leave Approvals</div>
                        <small class="text-muted">Manage annual leave roster</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
                <a href="{{ route('hod.employees') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-people text-success fs-5"></i>
                    <div>
                        <div class="fw-medium">Department Employees</div>
                        <small class="text-muted">View all employees in dept</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
                <a href="{{ route('hod.roster') }}" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-3">
                    <i class="bi bi-calendar3-week text-primary fs-5"></i>
                    <div>
                        <div class="fw-medium">Department Rosters</div>
                        <small class="text-muted">View duty & leave rosters</small>
                    </div>
                    <i class="bi bi-chevron-right ms-auto text-muted"></i>
                </a>
            </div>
        </div>
    </div>

    {{-- Recent Pending Requests --}}
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-clock-history me-2 text-primary"></i>Pending Leave Requests</h6>
                <a href="{{ route('hod.leaves') }}" class="btn btn-sm btn-outline-primary">View All</a>
            </div>
            <div class="card-body p-0">
                @forelse($recentRequests as $leave)
                    <div class="d-flex align-items-center gap-3 px-3 py-3 border-bottom">
                        <div class="rounded-circle bg-warning bg-opacity-10 p-2">
                            <i class="bi bi-person text-warning"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-medium">{{ $leave->employee?->full_name }}</div>
                            <small class="text-muted">
                                {{ $leave->leaveType?->name }}
                                &middot; {{ \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') }}
                                – {{ \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') }}
                                ({{ $leave->days_requested }} days)
                            </small>
                        </div>
                        <a href="{{ route('hod.leaves.show', $leave) }}" class="btn btn-sm btn-outline-primary">
                            Review
                        </a>
                    </div>
                @empty
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-check-circle fs-3 d-block mb-2 text-success"></i>
                        No pending leave requests.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
