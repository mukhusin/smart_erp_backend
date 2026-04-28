@extends('layouts.app')
@section('title','Payslip')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('payroll.sheets.index') }}">Payroll Sheets</a></li>
    <li class="breadcrumb-item"><a href="{{ route('payroll.sheets.show', $sheet) }}">{{ $sheet->name }}</a></li>
    <li class="breadcrumb-item active">Payslip</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-8">

<div class="d-flex justify-content-between mb-3">
    <div></div>
    <button onclick="window.print()" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-printer me-1"></i> Print
    </button>
</div>

{{-- Payslip card --}}
<div class="card shadow-sm" id="payslip-card">
    <div class="card-body p-4">
        {{-- Header --}}
        <div class="d-flex justify-content-between align-items-start mb-4">
            <div>
                <h5 class="fw-bold mb-0">{{ config('app.name') }}</h5>
                <small class="text-muted">Salary Payslip</small>
            </div>
            <div class="text-end">
                <h6 class="mb-0">{{ $sheet->month }} {{ $sheet->year }}</h6>
                <small class="text-muted">{{ $sheet->payroll_from_date }} — {{ $sheet->payroll_to_date }}</small>
            </div>
        </div>

        <hr>

        {{-- Employee info --}}
        <div class="row g-3 mb-4">
            <div class="col-md-6">
                <table class="table table-sm table-borderless mb-0">
                    <tr><td class="text-muted small fw-semibold" style="width:40%">Employee</td><td class="fw-bold">{{ $detail->full_name }}</td></tr>
                    <tr><td class="text-muted small">Position</td><td>{{ $detail->job_position }}</td></tr>
                    <tr><td class="text-muted small">Bank</td><td>{{ $detail->bank_name }} {{ $detail->bank_branch }}</td></tr>
                    <tr><td class="text-muted small">Account</td><td>{{ $detail->bank_account }}</td></tr>
                </table>
            </div>
        </div>

        <div class="row g-3">
            {{-- Earnings --}}
            <div class="col-md-6">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th colspan="2">Earnings</th></tr></thead>
                        <tbody>
                            <tr><td>Basic Salary</td><td class="text-end">{{ number_format($detail->basic_salary,2) }}</td></tr>
                            @foreach($allowances as $al)
                            <tr><td class="text-muted small">{{ $al->allowance_name }}</td><td class="text-end">{{ number_format($al->amount,2) }}</td></tr>
                            @endforeach
                            @foreach($overtimes as $ot)
                            <tr><td class="text-muted small">Overtime ({{ $ot->date_of_task_done }})</td><td class="text-end">{{ number_format($ot->amount,2) }}</td></tr>
                            @endforeach
                            @if($detail->relief > 0)
                            <tr><td class="text-muted small">Relief</td><td class="text-end">{{ number_format($detail->relief,2) }}</td></tr>
                            @endif
                        </tbody>
                        <tfoot class="fw-bold table-light">
                            <tr><td>Gross Salary</td><td class="text-end">{{ number_format($detail->gross_salary,2) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>

            {{-- Deductions --}}
            <div class="col-md-6">
                <div class="table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th colspan="2">Deductions</th></tr></thead>
                        <tbody>
                            @if($detail->nssf > 0)<tr><td>NSSF</td><td class="text-end text-danger">{{ number_format($detail->nssf,2) }}</td></tr>@endif
                            @if($detail->nhif > 0)<tr><td>NHIF</td><td class="text-end text-danger">{{ number_format($detail->nhif,2) }}</td></tr>@endif
                            @if($detail->heslb > 0)<tr><td>HESLB</td><td class="text-end text-danger">{{ number_format($detail->heslb,2) }}</td></tr>@endif
                            @if($detail->tughe > 0)<tr><td>TUGHE</td><td class="text-end text-danger">{{ number_format($detail->tughe,2) }}</td></tr>@endif
                            @if($detail->paye > 0)<tr><td>PAYE</td><td class="text-end text-danger">{{ number_format($detail->paye,2) }}</td></tr>@endif
                            @if($detail->ef > 0)<tr><td>Employee Fund</td><td class="text-end text-danger">{{ number_format($detail->ef,2) }}</td></tr>@endif
                            @if($detail->nic > 0)<tr><td>NIC</td><td class="text-end text-danger">{{ number_format($detail->nic,2) }}</td></tr>@endif
                            @foreach($loans as $l)
                            <tr><td class="small">{{ $l->loan_provider }} (Loan)</td><td class="text-end text-danger">{{ number_format($l->instalment,2) }}</td></tr>
                            @endforeach
                        </tbody>
                        <tfoot class="fw-bold table-light">
                            <tr><td>Total Deductions</td><td class="text-end text-danger">{{ number_format($detail->total_deduction,2) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>

        <hr>

        {{-- Net pay --}}
        <div class="d-flex justify-content-between align-items-center">
            <h5 class="mb-0">Net Pay</h5>
            <h4 class="fw-bold text-success mb-0">TZS {{ number_format($detail->net_salary,2) }}</h4>
        </div>

        @if($detail->nssf_employer > 0 || $detail->nhif_employer > 0 || $detail->wcf > 0 || $detail->sdl > 0)
        <hr>
        <div class="text-muted small">
            <strong>Employer Contributions (not deducted from net pay):</strong>
            @if($detail->nssf_employer > 0) NSSF: {{ number_format($detail->nssf_employer,2) }} &nbsp; @endif
            @if($detail->nhif_employer > 0) NHIF: {{ number_format($detail->nhif_employer,2) }} &nbsp; @endif
            @if($detail->wcf > 0) WCF: {{ number_format($detail->wcf,2) }} &nbsp; @endif
            @if($detail->sdl > 0) SDL: {{ number_format($detail->sdl,2) }} @endif
        </div>
        @endif
    </div>
</div>

</div>
</div>
@endsection

@push('styles')
<style>
@media print {
    nav, .sidebar, .topbar, .breadcrumb, button { display: none !important; }
    #payslip-card { box-shadow: none !important; }
}
</style>
@endpush
