@extends('layouts.app')
@section('title', 'Contract Report')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Reports</a></li>
    <li class="breadcrumb-item active">Contract Report</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-0">Contract Report</h5>
        <p class="page-subtitle mb-0">{{ $contracts->count() }} contract(s) matched</p>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer me-1"></i> Print
    </button>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.contracts') }}" class="row g-3">
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
                <label class="form-label small fw-semibold">Contract Type</label>
                <select name="nature_of_contract_id" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    @foreach($contractTypes as $ct)
                        <option value="{{ $ct->id }}" {{ request('nature_of_contract_id') == $ct->id ? 'selected' : '' }}>
                            {{ $ct->contract_name }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Filter</label>
                <select name="filter" class="form-select form-select-sm">
                    <option value="expiring" {{ $filter === 'expiring' ? 'selected' : '' }}>Expiring Soon</option>
                    <option value="expired"  {{ $filter === 'expired'  ? 'selected' : '' }}>Expired</option>
                    <option value="all"      {{ $filter === 'all'      ? 'selected' : '' }}>All Active</option>
                </select>
            </div>
            @if($filter === 'expiring')
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Expires Within (days)</label>
                <input type="number" name="expires_in" class="form-control form-control-sm" value="{{ $expiresInDays }}" min="1" max="365">
            </div>
            @endif
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="{{ route('reports.contracts') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

@if($filter === 'expiring' && $contracts->count())
<div class="alert alert-warning border-0 shadow-sm mb-4 no-print" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i>
    <strong>{{ $contracts->count() }}</strong> contract(s) expiring within the next <strong>{{ $expiresInDays }}</strong> days.
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
                        <th>Contract Type</th>
                        <th>Contract No</th>
                        <th>Start Date</th>
                        <th>End Date</th>
                        <th>Days Remaining</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($contracts as $i => $contract)
                    @php
                        $daysLeft = $contract->end_date ? $today->diffInDays($contract->end_date, false) : null;
                        $rowClass = '';
                        if ($daysLeft !== null) {
                            if ($daysLeft < 0)  $rowClass = 'table-danger';
                            elseif ($daysLeft <= 14) $rowClass = 'table-danger';
                            elseif ($daysLeft <= 30) $rowClass = 'table-warning';
                        }
                    @endphp
                    <tr class="{{ $rowClass }}">
                        <td class="text-muted small">{{ $i + 1 }}</td>
                        <td class="fw-semibold small">
                            <a href="{{ route('hr.employees.show', $contract->employee_id) }}" class="text-decoration-none no-print">
                                {{ $contract->employee?->full_name ?? '—' }}
                            </a>
                            <span class="print-only">{{ $contract->employee?->full_name ?? '—' }}</span>
                        </td>
                        <td class="small">{{ $contract->employee?->department?->name ?? '—' }}</td>
                        <td class="small">{{ $contract->employee?->jobPosition?->title ?? '—' }}</td>
                        <td class="small">{{ $contract->natureOfContract?->contract_name ?? '—' }}</td>
                        <td class="small text-muted">{{ $contract->contract_no ?? '—' }}</td>
                        <td class="small">{{ $contract->start_date?->format('d M Y') ?? '—' }}</td>
                        <td class="small">
                            @if($contract->end_date)
                                {{ $contract->end_date->format('d M Y') }}
                            @else
                                <span class="text-muted">Indefinite</span>
                            @endif
                        </td>
                        <td class="small">
                            @if($daysLeft === null)
                                <span class="text-muted">—</span>
                            @elseif($daysLeft < 0)
                                <span class="text-danger fw-semibold">Expired {{ abs((int)$daysLeft) }}d ago</span>
                            @elseif($daysLeft == 0)
                                <span class="text-danger fw-bold">Expires today</span>
                            @elseif($daysLeft <= 14)
                                <span class="text-danger fw-semibold">{{ (int)$daysLeft }} days</span>
                            @elseif($daysLeft <= 30)
                                <span class="text-warning fw-semibold">{{ (int)$daysLeft }} days</span>
                            @else
                                <span class="text-success">{{ (int)$daysLeft }} days</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="9" class="text-center text-muted py-4">No contracts matched the selected filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($contracts->count())
    <div class="card-footer text-muted small">
        Total: {{ $contracts->count() }} contract(s)
    </div>
    @endif
</div>
@endsection

@push('styles')
<style>
@media print {
    .no-print, .sidebar, .topbar, nav, .breadcrumb-section { display: none !important; }
    .print-only { display: inline !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
.print-only { display: none; }
</style>
@endpush
