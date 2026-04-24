@extends('layouts.app')
@section('title', 'Annual Leave Approvals')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('hod.dashboard') }}">HOD</a></li>
    <li class="breadcrumb-item active">Annual Leaves</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-calendar2-heart me-2 text-primary"></i>Annual Leave Approvals</h5>
</div>

{{-- Status Filter --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 d-flex gap-2 flex-wrap">
        @foreach(['pending' => 'Pending', 'hod_approved' => 'Forwarded to HR', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All'] as $val => $label)
            <a href="{{ route('hod.annual-leaves', ['status' => $val]) }}"
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
                    <th>Roster</th>
                    <th>Start</th>
                    <th>End</th>
                    <th>Days</th>
                    <th>Year</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse($leaves as $leave)
                    <tr>
                        <td class="fw-medium">{{ $leave->employee?->full_name }}</td>
                        <td class="small text-muted">{{ $leave->roster?->title ?? '—' }}</td>
                        <td>{{ \Carbon\Carbon::parse($leave->start_date)->format('d M Y') }}</td>
                        <td>{{ \Carbon\Carbon::parse($leave->end_date)->format('d M Y') }}</td>
                        <td>{{ $leave->days }}</td>
                        <td>{{ $leave->leave_year }}</td>
                        <td>
                            @php $cls = match($leave->status ?? '') {
                                'approved' => 'bg-success', 'pending' => 'bg-warning text-dark',
                                'hod_approved' => 'bg-info', 'rejected' => 'bg-danger',
                                default => 'bg-secondary'
                            }; @endphp
                            <span class="badge {{ $cls }}">{{ ucfirst(str_replace('_',' ',$leave->status ?? 'pending')) }}</span>
                        </td>
                        <td>
                            @if(in_array($leave->status ?? 'pending', ['pending']))
                                <div class="d-flex gap-1">
                                    <form method="POST" action="{{ route('hod.annual-leaves.approve', $leave) }}" class="d-inline">
                                        @csrf
                                        <button type="submit" class="btn btn-success btn-sm"
                                                onclick="return confirm('Approve this annual leave?')">
                                            <i class="bi bi-check-lg"></i>
                                        </button>
                                    </form>
                                    <button type="button" class="btn btn-danger btn-sm"
                                            data-bs-toggle="modal" data-bs-target="#rejectModal{{ $leave->id }}">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                </div>

                                {{-- Reject Modal --}}
                                <div class="modal fade" id="rejectModal{{ $leave->id }}" tabindex="-1">
                                    <div class="modal-dialog modal-sm">
                                        <form method="POST" action="{{ route('hod.annual-leaves.reject', $leave) }}">
                                            @csrf
                                            <div class="modal-content">
                                                <div class="modal-header">
                                                    <h6 class="modal-title">Reject Annual Leave</h6>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <label class="form-label small">Reason <span class="text-danger">*</span></label>
                                                    <textarea name="remark" class="form-control form-control-sm" rows="3" required></textarea>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                                </div>
                                            </div>
                                        </form>
                                    </div>
                                </div>
                            @else
                                <span class="text-muted small">{{ $leave->hod_remark ?? '—' }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="text-center text-muted py-4">No annual leave requests found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($leaves->hasPages())
        <div class="card-footer bg-transparent">{{ $leaves->links() }}</div>
    @endif
</div>
@endsection
