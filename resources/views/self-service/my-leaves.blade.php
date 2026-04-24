@extends('layouts.app')
@section('title', 'My Leave Requests')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('self-service.dashboard') }}">Self-Service</a></li>
    <li class="breadcrumb-item active">My Leaves</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-calendar-x me-2 text-primary"></i>My Leave Requests</h5>
    <a href="{{ route('self-service.request-leave') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>New Request
    </a>
</div>

{{-- Filter --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <form method="GET" class="d-flex gap-2 align-items-center flex-wrap">
            <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
                <option value="">All Statuses</option>
                @foreach(['pending','hod_approved','approved','rejected','active','reported'] as $s)
                    <option value="{{ $s }}" {{ request('status') === $s ? 'selected' : '' }}>
                        {{ ucfirst(str_replace('_',' ',$s)) }}
                    </option>
                @endforeach
            </select>
            @if(request('status'))
                <a href="{{ route('self-service.my-leaves') }}" class="btn btn-sm btn-outline-secondary">Clear</a>
            @endif
        </form>
    </div>
</div>

{{-- Other Leave Requests --}}
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent border-bottom">
        <h6 class="mb-0">Other Leave Requests</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Type</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>HOD Remark</th>
                    <th>HR Remark</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                @forelse($leaves as $leave)
                    <tr>
                        <td>{{ $leave->leaveType?->name ?? '—' }}</td>
                        <td>{{ \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') }}</td>
                        <td>{{ $leave->days_requested }}</td>
                        <td>
                            @php
                                $cls = match($leave->status) {
                                    'approved'     => 'bg-success',
                                    'pending'      => 'bg-warning text-dark',
                                    'hod_approved' => 'bg-info',
                                    'rejected'     => 'bg-danger',
                                    'active'       => 'bg-primary',
                                    'reported'     => 'bg-secondary',
                                    default        => 'bg-secondary',
                                };
                            @endphp
                            <span class="badge {{ $cls }}">{{ ucfirst(str_replace('_',' ',$leave->status)) }}</span>
                        </td>
                        <td class="small text-muted">{{ $leave->hod_remark ?? '—' }}</td>
                        <td class="small text-muted">{{ $leave->hr_office_remark ?? '—' }}</td>
                        <td class="small text-muted">{{ $leave->created_at->format('d M Y') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No leave requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($leaves->hasPages())
        <div class="card-footer bg-transparent">{{ $leaves->links() }}</div>
    @endif
</div>

{{-- Annual Leave Roster --}}
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent border-bottom">
        <h6 class="mb-0">Annual Leave</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Roster</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>HOD Remark</th>
                    <th>Year</th>
                </tr>
            </thead>
            <tbody>
                @forelse($annualLeaves as $al)
                    <tr>
                        <td>{{ $al->roster?->title ?? '—' }}</td>
                        <td>{{ \Carbon\Carbon::parse($al->start_date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($al->end_date)->format('d M Y') }}</td>
                        <td>{{ $al->days }}</td>
                        <td>
                            @php $cls = match($al->status ?? '') {
                                'approved' => 'bg-success', 'pending' => 'bg-warning text-dark',
                                'hod_approved' => 'bg-info', 'rejected' => 'bg-danger',
                                default => 'bg-secondary'
                            }; @endphp
                            <span class="badge {{ $cls }}">{{ ucfirst(str_replace('_',' ',$al->status ?? 'pending')) }}</span>
                        </td>
                        <td class="small text-muted">{{ $al->hod_remark ?? '—' }}</td>
                        <td>{{ $al->leave_year }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-center text-muted py-4">No annual leave records.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
