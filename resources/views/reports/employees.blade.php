@extends('layouts.app')
@section('title', 'Employee Report')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('reports.index') }}">Reports</a></li>
    <li class="breadcrumb-item active">Employee Report</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-0">Employee Report</h5>
        <p class="page-subtitle mb-0">{{ $employees->count() }} employee(s) matched</p>
    </div>
    <button onclick="window.print()" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-printer me-1"></i> Print
    </button>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-4 no-print">
    <div class="card-body">
        <form method="GET" action="{{ route('reports.employees') }}" class="row g-3">
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
                <label class="form-label small fw-semibold">Gender</label>
                <select name="gender" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="male"   {{ request('gender') == 'male'   ? 'selected' : '' }}>Male</option>
                    <option value="female" {{ request('gender') == 'female' ? 'selected' : '' }}>Female</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Status</label>
                <select name="employment_status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="active"      {{ request('employment_status') == 'active'      ? 'selected' : '' }}>Active</option>
                    <option value="inactive"    {{ request('employment_status') == 'inactive'    ? 'selected' : '' }}>Inactive</option>
                    <option value="terminated"  {{ request('employment_status') == 'terminated'  ? 'selected' : '' }}>Terminated</option>
                    <option value="on_probation"{{ request('employment_status') == 'on_probation'? 'selected' : '' }}>On Probation</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold">Employee Type</label>
                <select name="emp_type" class="form-select form-select-sm">
                    <option value="">All</option>
                    <option value="permanent"  {{ request('emp_type') == 'permanent'  ? 'selected' : '' }}>Permanent</option>
                    <option value="contract"   {{ request('emp_type') == 'contract'   ? 'selected' : '' }}>Contract</option>
                    <option value="temporary"  {{ request('emp_type') == 'temporary'  ? 'selected' : '' }}>Temporary</option>
                    <option value="intern"     {{ request('emp_type') == 'intern'     ? 'selected' : '' }}>Intern</option>
                </select>
            </div>
            <div class="col-md-3 d-flex align-items-end gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i> Filter
                </button>
                <a href="{{ route('reports.employees') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Employee No</th>
                        <th>Name</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Gender</th>
                        <th>Type</th>
                        <th>Employment Date</th>
                        <th>Contract</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($employees as $i => $emp)
                    <tr>
                        <td class="text-muted small">{{ $i + 1 }}</td>
                        <td class="fw-semibold small">{{ $emp->employee_no }}</td>
                        <td>
                            <a href="{{ route('hr.employees.show', $emp) }}" class="text-decoration-none no-print">
                                {{ $emp->full_name }}
                            </a>
                            <span class="print-only">{{ $emp->full_name }}</span>
                        </td>
                        <td class="small">{{ $emp->department?->name ?? '—' }}</td>
                        <td class="small">{{ $emp->jobPosition?->title ?? '—' }}</td>
                        <td class="small">{{ ucfirst($emp->gender ?? '—') }}</td>
                        <td class="small">{{ ucfirst($emp->emp_type ?? '—') }}</td>
                        <td class="small">{{ $emp->employment_date?->format('d M Y') ?? '—' }}</td>
                        <td class="small">{{ $emp->activeContract?->natureOfContract?->contract_name ?? '—' }}</td>
                        <td>
                            @php
                                $s = $emp->employment_status;
                                $badge = match($s) {
                                    'active'       => 'success',
                                    'inactive'     => 'secondary',
                                    'terminated'   => 'danger',
                                    'on_probation' => 'warning',
                                    default        => 'secondary',
                                };
                            @endphp
                            <span class="badge bg-{{ $badge }}">{{ ucfirst(str_replace('_',' ',$s ?? 'N/A')) }}</span>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">No employees matched the selected filters.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
    @if($employees->count())
    <div class="card-footer text-muted small">
        Total: {{ $employees->count() }} employee(s)
        @if(request()->hasAny(['department_id','gender','employment_status','emp_type']))
            &nbsp;·&nbsp; <a href="{{ route('reports.employees') }}" class="no-print">Clear filters</a>
        @endif
    </div>
    @endif
</div>
@endsection

@push('styles')
<style>
@media print {
    .no-print, .sidebar, .topbar, nav, .breadcrumb-section { display: none !important; }
    .print-only { display: inline !important; }
    a { color: inherit !important; text-decoration: none !important; }
    .card { box-shadow: none !important; border: 1px solid #dee2e6 !important; }
}
.print-only { display: none; }
</style>
@endpush
