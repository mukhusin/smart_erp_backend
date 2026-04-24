@extends('layouts.app')
@section('title', 'Attendance Summary')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Reports</a></li>
    <li class="breadcrumb-item active">Attendance Summary</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-0">Attendance Summary</h5>
        <p class="page-subtitle mb-0">
            {{ $fromDate->format('d M Y') }} — {{ $toDate->format('d M Y') }}
            &nbsp;·&nbsp; {{ $grouped->count() }} employee(s)
        </p>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer me-1"></i> Print
    </button>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.attendance') }}" class="row g-3">
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
            <div class="col-md-3">
                <label class="form-label small fw-semibold">From Date</label>
                <input type="date" name="from_date" class="form-control form-control-sm" value="{{ $fromDate->toDateString() }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold">To Date</label>
                <input type="date" name="to_date" class="form-control form-control-sm" value="{{ $toDate->toDateString() }}">
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="{{ route('reports.attendance') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- Summary totals --}}
@if($grouped->count())
@php
    $totalPresent  = $grouped->sum('present');
    $totalAbsent   = $grouped->sum('absent');
    $totalOnLeave  = $grouped->sum('on_leave');
    $totalLate     = $grouped->sum('late');
    $totalAll      = $grouped->sum('total');
@endphp
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-4 text-success">{{ $totalPresent }}</div>
            <div class="text-muted small">Present Days</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-4 text-danger">{{ $totalAbsent }}</div>
            <div class="text-muted small">Absent Days</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-4 text-info">{{ $totalOnLeave }}</div>
            <div class="text-muted small">On Leave Days</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3">
            <div class="fw-bold fs-4 text-warning">{{ $totalLate }}</div>
            <div class="text-muted small">Late Arrivals</div>
        </div>
    </div>
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
                        <th>Position</th>
                        <th class="text-center">Total</th>
                        <th class="text-center text-success">Present</th>
                        <th class="text-center text-danger">Absent</th>
                        <th class="text-center text-info">On Leave</th>
                        <th class="text-center text-warning">Late</th>
                        <th class="text-center">Attendance %</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($grouped as $i => $row)
                    @php
                        $pct = $row['total'] > 0 ? round(($row['present'] / $row['total']) * 100) : 0;
                        $pctClass = $pct >= 90 ? 'success' : ($pct >= 75 ? 'warning' : 'danger');
                    @endphp
                    <tr>
                        <td class="text-muted small">{{ $loop->iteration }}</td>
                        <td class="fw-semibold small">{{ $row['employee']?->full_name ?? '—' }}</td>
                        <td class="small">{{ $row['employee']?->department?->name ?? '—' }}</td>
                        <td class="small">{{ $row['employee']?->jobPosition?->title ?? '—' }}</td>
                        <td class="text-center small">{{ $row['total'] }}</td>
                        <td class="text-center small text-success fw-semibold">{{ $row['present'] }}</td>
                        <td class="text-center small text-danger">{{ $row['absent'] }}</td>
                        <td class="text-center small text-info">{{ $row['on_leave'] }}</td>
                        <td class="text-center small text-warning">{{ $row['late'] }}</td>
                        <td class="text-center">
                            <span class="badge bg-{{ $pctClass }}">{{ $pct }}%</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No attendance records for the selected period.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($grouped->count())
    <div class="card-footer text-muted small">
        Period: {{ $fromDate->format('d M Y') }} — {{ $toDate->format('d M Y') }}
        &nbsp;·&nbsp; {{ $grouped->count() }} employee(s)
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
