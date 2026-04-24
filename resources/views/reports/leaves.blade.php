@extends('layouts.app')
@section('title', 'Leave Report')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Reports</a></li>
    <li class="breadcrumb-item active">Leave Report</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-0">Leave Report</h5>
        <p class="page-subtitle mb-0">{{ $leaves->count() }} record(s) matched</p>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer me-1"></i> Print
    </button>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.leaves') }}" class="row g-3">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Department</label>
                <select name="department_id" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    @foreach($departments as $dept)
                        <option value="{{ $dept->id }}" {{ request('department_id') == $dept->id ? 'selected' : '' }}>
                            {{ $dept->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Leave Type</label>
                <select name="leave_type_id" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach($leaveTypes as $lt)
                        <option value="{{ $lt->id }}" {{ request('leave_type_id') == $lt->id ? 'selected' : '' }}>
                            {{ $lt->name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['pending','hod_approved','approved','active','reported','rejected'] as $st)
                        <option value="{{ $st }}" {{ request('status') == $st ? 'selected' : '' }}>
                            {{ ucfirst(str_replace('_',' ',$st)) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="{{ request('from_date') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">To Date</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="{{ request('to_date') }}">
            </div>
            <div class="col-md-1 d-flex align-items-end gap-1">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel"></i>
                </button>
                <a href="{{ route('reports.leaves') }}" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x"></i>
                </a>
            </div>
        </form>
    </div>
</div>

{{-- Summary cards --}}
@if($summary->isNotEmpty())
<div class="row g-3 mb-4">
    @foreach($summary as $type => $data)
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-4 text-primary">{{ $data['count'] }}</div>
            <div class="text-muted small">{{ $type }}</div>
            <div class="text-muted" style="font-size:.7rem">{{ $data['days'] }} days total</div>
        </div>
    </div>
    @endforeach
</div>
@endif

{{-- Table --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Employee</th>
                        <th>Department</th>
                        <th>Leave Type</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Days</th>
                        <th>Applied</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($leaves as $i => $leave)
                    @php
                        $badge = match($leave->status) {
                            'approved'     => 'success',
                            'active'       => 'info',
                            'reported'     => 'secondary',
                            'pending'      => 'warning',
                            'hod_approved' => 'primary',
                            'rejected'     => 'danger',
                            default        => 'secondary',
                        };
                    @endphp
                    <tr>
                        <td class="text-muted small">{{ $i + 1 }}</td>
                        <td class="fw-semibold small">{{ $leave->employee?->full_name ?? '—' }}</td>
                        <td class="small">{{ $leave->employee?->department?->name ?? '—' }}</td>
                        <td class="small">{{ $leave->leaveType?->name ?? '—' }}</td>
                        <td class="small">{{ $leave->requested_start_date ? \Carbon\Carbon::parse($leave->requested_start_date)->format('d M Y') : '—' }}</td>
                        <td class="small">{{ $leave->requested_end_date ? \Carbon\Carbon::parse($leave->requested_end_date)->format('d M Y') : '—' }}</td>
                        <td class="small text-center">{{ $leave->days_requested ?? '—' }}</td>
                        <td class="small text-muted">{{ $leave->created_at->format('d M Y') }}</td>
                        <td>
                            <span class="badge bg-{{ $badge }}">
                                {{ ucfirst(str_replace('_',' ',$leave->status)) }}
                            </span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No leave records matched the selected filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($leaves->count())
    <div class="card-footer text-muted small">
        Total: {{ $leaves->count() }} record(s) · {{ $leaves->sum('days_requested') }} days
    </div>
    @endif
</div>
@endsection

@push('styles')
<style>
@media print {
    .no-print, .sidebar, .topbar, nav, .breadcrumb-section { display: none !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
</style>
@endpush
