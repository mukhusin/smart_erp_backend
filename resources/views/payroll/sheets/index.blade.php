@extends('layouts.app')
@section('title','Payroll Sheets')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Payroll Sheets</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Payroll Sheets</h5>
        <p class="page-subtitle">Monthly salary runs</p>
    </div>
    <a href="{{ route('payroll.sheets.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Sheet
    </a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Year</label>
                <select name="year" class="form-select form-select-sm">
                    <option value="">All Years</option>
                    @foreach(range(date('Y'), date('Y')-3) as $y)
                        <option value="{{ $y }}" {{ request('year')==$y?'selected':'' }}>{{ $y }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['pending','processing','submitted_payroll','submitted_director','submitted_finance','approved','rejected'] as $s)
                        <option value="{{ $s }}" {{ request('status')==$s?'selected':'' }}>{{ ucwords(str_replace('_',' ',$s)) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-auto">
                <button class="btn btn-sm btn-outline-secondary">Filter</button>
                <a href="{{ route('payroll.sheets.index') }}" class="btn btn-sm btn-link">Clear</a>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Period</th>
                    <th>Employees</th>
                    <th>Gross</th>
                    <th>Net</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($sheets as $sheet)
                @php
                    $statusColor = match($sheet->status) {
                        'approved'          => 'success',
                        'rejected'          => 'danger',
                        'submitted_finance' => 'info',
                        'submitted_director','submitted_payroll' => 'warning',
                        default             => 'secondary',
                    };
                @endphp
                <tr>
                    <td class="text-muted small">{{ $sheet->id }}</td>
                    <td class="fw-semibold">{{ $sheet->name }}</td>
                    <td class="small">{{ $sheet->payroll_from_date }} — {{ $sheet->payroll_to_date }}</td>
                    <td>{{ $sheet->details->count() }}</td>
                    <td>{{ number_format($sheet->details->sum('gross_salary'),2) }}</td>
                    <td>{{ number_format($sheet->details->sum('net_salary'),2) }}</td>
                    <td><span class="badge bg-{{ $statusColor }}">{{ ucwords(str_replace('_',' ',$sheet->status)) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('payroll.sheets.show', $sheet) }}" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i>
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No payroll sheets yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($sheets->hasPages())
    <div class="card-footer">{{ $sheets->links() }}</div>
    @endif
</div>
@endsection
