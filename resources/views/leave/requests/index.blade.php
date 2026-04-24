@extends('layouts.app')
@section('title','Leave Requests')

@section('breadcrumb')
    <li class="breadcrumb-item active">Leave Requests</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Leave Requests</h5>
    <a href="{{ route('leave.requests.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Request
    </a>
</div>

{{-- Quick status tabs --}}
<div class="d-flex gap-2 mb-3 flex-wrap">
    @foreach([
        '' => 'All',
        'pending' => 'Pending',
        'hod_approved' => 'Awaiting HR ⭐',
        'approved' => 'Approved',
        'active' => 'Active',
        'rejected' => 'Rejected',
    ] as $val => $label)
        <a href="{{ route('leave.requests.index', array_merge(request()->except('status','page'), ['status' => $val])) }}"
           class="btn btn-sm {{ request('status', '') === $val ? 'btn-primary' : 'btn-outline-secondary' }}">
            {{ $label }}
        </a>
    @endforeach
</div>

<form class="row g-2 mb-3" method="GET">
    <input type="hidden" name="status" value="{{ request('status') }}">
    <div class="col-md-5">
        <input type="text" name="search" class="form-control form-control-sm"
               placeholder="Search employee..." value="{{ request('search') }}">
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Search</button></div>
    @if(request('search'))<div class="col-auto"><a href="{{ route('leave.requests.index', ['status' => request('status')]) }}" class="btn btn-sm btn-outline-danger">Clear</a></div>@endif
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Days</th>
                    <th>HOD</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leaves as $leave)
                <tr>
                    <td class="fw-medium">{{ $leave->employee?->full_name }}</td>
                    <td>{{ $leave->leaveType?->name ?? '—' }}</td>
                    <td>{{ \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') }}</td>
                    <td>{{ $leave->days_requested }}</td>
                    <td>
                        @if($leave->hod_is_approved)
                            <span class="badge bg-success-subtle text-success">Approved</span>
                        @elseif($leave->hod_is_approved === false && $leave->hod_id)
                            <span class="badge bg-danger-subtle text-danger">Rejected</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">Pending</span>
                        @endif
                    </td>
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
                        <span class="badge {{ $cls }}">{{ ucfirst(str_replace('_', ' ', $leave->status)) }}</span>
                    </td>
                    <td class="small text-muted">{{ $leave->created_at->format('d M Y') }}</td>
                    <td class="text-end">
                        <a href="{{ route('leave.requests.show', $leave) }}" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-eye"></i>
                        </a>
                        @if(in_array($leave->status, ['pending','hod_approved']))
                            <form method="POST" action="{{ route('leave.requests.approve', $leave) }}" class="d-inline">
                                @csrf
                                <button class="btn btn-sm btn-success" title="Approve"
                                        onclick="return confirm('Approve this leave request?')">
                                    <i class="bi bi-check-lg"></i>
                                </button>
                            </form>
                        @endif
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No leave requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($leaves->hasPages())
    <div class="card-footer">{{ $leaves->links() }}</div>
    @endif
</div>
@endsection
