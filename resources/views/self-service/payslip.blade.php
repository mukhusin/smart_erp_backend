@extends('layouts.app')
@section('title', 'My Payslips')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('self-service.dashboard') }}">Self-Service</a></li>
    <li class="breadcrumb-item active">My Payslips</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="mb-0"><i class="bi bi-receipt me-2 text-primary"></i>My Payslips</h5>
    <span class="text-muted small">{{ $employee->full_name }}</span>
</div>

<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>Payroll Period</th>
                    <th>Month / Year</th>
                    <th>Basic Salary</th>
                    <th>Total Allowance</th>
                    <th>Total Deduction</th>
                    <th class="text-success">Net Salary</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                @forelse($payslips as $slip)
                    <tr>
                        <td class="fw-medium">{{ $slip->payrollSheet?->name ?? '—' }}</td>
                        <td>{{ $slip->payrollSheet?->month ?? '—' }} / {{ $slip->payrollSheet?->year ?? '—' }}</td>
                        <td>{{ number_format($slip->basic_salary, 0) }}</td>
                        <td>{{ number_format($slip->total_allowance ?? 0, 0) }}</td>
                        <td>{{ number_format($slip->total_deduction ?? 0, 0) }}</td>
                        <td class="fw-bold text-success">{{ number_format($slip->net_salary, 0) }}</td>
                        <td>
                            @if($slip->payrollSheet?->payroll_approved)
                                <span class="badge bg-success">Approved</span>
                            @else
                                <span class="badge bg-warning text-dark">Pending</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="text-center text-muted py-5">
                            <i class="bi bi-receipt fs-3 d-block mb-2"></i>
                            No payslip records available yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($payslips->hasPages())
        <div class="card-footer bg-transparent">{{ $payslips->links() }}</div>
    @endif
</div>
@endsection
