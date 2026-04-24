@extends('layouts.app')
@section('title', 'Department Leave Requests')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hod.dashboard') }}">HOD</a></li>
    <li class="breadcrumb-item active">Leave Requests</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-calendar-x me-2 text-primary"></i>Department Leave Requests</h5>
</div>

{{-- Status Filter --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 d-flex gap-2 flex-wrap">
        @foreach(['pending' => 'Pending', 'hod_approved' => 'Forwarded to HR', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $val => $label)
            <a href="{{ route('hod.leaves', ['status' => $val]) }}"
               class="btn btn-sm {{ $status === $val ? 'btn-primary' : 'btn-outline-secondary' }}">
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Employee</th>
                    <th>Leave Type</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Days</th>
                    <th>Status</th>
                    <th>Submitted</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($leaves as $leave)
                    <tr>
                        <td class="fw-medium">{{ $leave->employee?->full_name }}</td>
                        <td>{{ $leave->leaveType?->name }}</td>
                        <td>{{ \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') }}</td>
                        <td>{{ $leave->days_requested }}</td>
                        <td>
                            @php $cls = match($leave->status) {
                                'approved' => 'bg-success', 'pending' => 'bg-warning text-dark',
                                'hod_approved' => 'bg-info', 'rejected' => 'bg-danger',
                                'active' => 'bg-primary', default => 'bg-secondary'
                            }; @endphp
                            <span class="badge {{ $cls }}">{{ ucfirst(str_replace('_',' ',$leave->status)) }}</span>
                        </td>
                        <td class="small text-muted">{{ $leave->created_at->format('d M Y') }}</td>
                        <td>
                            <a href="{{ route('hod.leaves.show', $leave) }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-eye"></i>
                            </a>
                        </td>
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
@endsection
