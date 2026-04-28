@extends('layouts.app')
@section('title','Payroll Sheet')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('payroll.sheets.index') }}">Payroll Sheets</a></li>
    <li class="breadcrumb-item active">{{ $sheet->name }}</li>
@endsection

@section('content')
@php
    $statusColor = match($sheet->status) {
        'approved'          => 'success',
        'rejected'          => 'danger',
        'submitted_finance' => 'info',
        'submitted_director','submitted_payroll' => 'warning',
        default             => 'secondary',
    };
@endphp

{{-- Header --}}
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-1">{{ $sheet->name }}</h5>
        <p class="page-subtitle mb-0">
            {{ $sheet->payroll_from_date }} — {{ $sheet->payroll_to_date }}
            &nbsp;·&nbsp; {{ $sheet->number_of_days }} days
            &nbsp;·&nbsp; <span class="badge bg-{{ $statusColor }}">{{ ucwords(str_replace('_',' ',$sheet->status)) }}</span>
        </p>
    </div>
    <div class="d-flex gap-2">
        @if(in_array($sheet->status,['pending','processing']))
        <a href="{{ route('payroll.sheets.edit', $sheet) }}" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        @endif
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#generateModal">
            <i class="bi bi-lightning-fill me-1"></i> Generate Salaries
        </button>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show py-2 small">
        <i class="bi bi-check-circle me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show py-2 small">
        <i class="bi bi-x-circle me-1"></i>{{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Summary cards --}}
<div class="row g-3 mb-4">
    @php $cards = [
        ['label'=>'Employees',  'value'=>$sheet->details->count(),                                          'icon'=>'people', 'color'=>'primary'],
        ['label'=>'Gross Salary','value'=>'TZS '.number_format($totals->gross ?? 0,2),                      'icon'=>'wallet2', 'color'=>'success'],
        ['label'=>'Total Deductions','value'=>'TZS '.number_format($totals->deduction ?? 0,2),              'icon'=>'dash-circle', 'color'=>'warning'],
        ['label'=>'Net Pay',    'value'=>'TZS '.number_format($totals->net ?? 0,2),                         'icon'=>'cash-coin', 'color'=>'info'],
        ['label'=>'PAYE',       'value'=>'TZS '.number_format($totals->paye ?? 0,2),                        'icon'=>'building-fill-check', 'color'=>'danger'],
        ['label'=>'WCF + SDL',  'value'=>'TZS '.number_format(($totals->wcf??0)+($totals->sdl??0),2),       'icon'=>'shield-fill', 'color'=>'secondary'],
    ]; @endphp
    @foreach($cards as $c)
    <div class="col-md-4 col-lg-2">
        <div class="card h-100 border-0 shadow-sm" style="border-top:3px solid var(--bs-{{ $c['color'] }}) !important">
            <div class="card-body py-3 px-3">
                <div class="text-muted small mb-1"><i class="bi bi-{{ $c['icon'] }} me-1"></i>{{ $c['label'] }}</div>
                <div class="fw-bold">{{ $c['value'] }}</div>
            </div>
        </div>
    </div>
    @endforeach
</div>

{{-- Employee salary table --}}
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Salary Details</span>
        <small class="text-muted">{{ $sheet->details->count() }} employees</small>
    </div>
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0" style="font-size:.82rem">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Employee</th>
                    <th>Position</th>
                    <th class="text-end">Basic</th>
                    <th class="text-end">Allowance</th>
                    <th class="text-end">Overtime</th>
                    <th class="text-end">Gross</th>
                    <th class="text-end">NSSF</th>
                    <th class="text-end">NHIF</th>
                    <th class="text-end">PAYE</th>
                    <th class="text-end">Deductions</th>
                    <th class="text-end">Net Pay</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($sheet->details as $i => $d)
                <tr>
                    <td class="text-muted">{{ $i+1 }}</td>
                    <td>{{ $d->full_name }}</td>
                    <td class="text-muted">{{ $d->job_position }}</td>
                    <td class="text-end">{{ number_format($d->basic_salary,2) }}</td>
                    <td class="text-end">{{ number_format($d->total_allowance,2) }}</td>
                    <td class="text-end">{{ number_format($d->total_overtime,2) }}</td>
                    <td class="text-end fw-semibold">{{ number_format($d->gross_salary,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($d->nssf,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($d->nhif,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($d->paye,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($d->total_deduction,2) }}</td>
                    <td class="text-end text-success fw-bold">{{ number_format($d->net_salary,2) }}</td>
                    <td>
                        <a href="{{ route('payroll.sheets.payslip', [$sheet, $d]) }}" class="btn btn-xs btn-outline-secondary py-0 px-1" title="Payslip">
                            <i class="bi bi-receipt"></i>
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="13" class="text-center text-muted py-4">
                    No salary data. Click <strong>Generate Salaries</strong> to compute.
                </td></tr>
                @endforelse
            </tbody>
            @if($sheet->details->count() > 0)
            <tfoot class="table-light fw-bold">
                <tr>
                    <td colspan="3">Totals</td>
                    <td class="text-end">{{ number_format($totals->basic ?? 0,2) }}</td>
                    <td class="text-end">{{ number_format($totals->allowance ?? 0,2) }}</td>
                    <td class="text-end">{{ number_format($totals->overtime ?? 0,2) }}</td>
                    <td class="text-end">{{ number_format($totals->gross ?? 0,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($totals->nssf ?? 0,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($totals->nhif ?? 0,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($totals->paye ?? 0,2) }}</td>
                    <td class="text-end text-danger">{{ number_format($totals->deduction ?? 0,2) }}</td>
                    <td class="text-end text-success">{{ number_format($totals->net ?? 0,2) }}</td>
                    <td></td>
                </tr>
            </tfoot>
            @endif
        </table>
    </div>
</div>

{{-- Approval trail --}}
<div class="card mb-4">
    <div class="card-header fw-semibold">Approval Trail</div>
    <div class="card-body">
        <form method="POST" action="{{ route('payroll.sheets.comment', $sheet) }}" class="row g-2 mb-3">
            @csrf
            @if($errors->any())
                <div class="col-12"><div class="alert alert-danger py-2 small">{{ $errors->first() }}</div></div>
            @endif
            <div class="col-md-4">
                <select name="remarks" class="form-select form-select-sm" required>
                    <option value="">— Select action —</option>
                    <option value="reviewed">Reviewed</option>
                    <option value="submit_to_payroll">Submit to Payroll Officer</option>
                    <option value="submit_to_director">Submit to Director</option>
                    <option value="submit_to_finance">Submit to Finance</option>
                    <option value="approved">Approve</option>
                    <option value="rejected">Reject</option>
                </select>
            </div>
            <div class="col-md-5">
                <input name="comment" class="form-control form-control-sm" placeholder="Comment…" required>
            </div>
            <div class="col-md-2">
                <input name="password" type="password" class="form-control form-control-sm" placeholder="Password" required>
            </div>
            <div class="col-md-1">
                <button class="btn btn-sm btn-primary w-100">Submit</button>
            </div>
        </form>

        @forelse($sheet->comments->sortByDesc('created_at') as $c)
        <div class="d-flex gap-3 border-bottom py-2">
            <div class="text-muted small" style="min-width:140px">{{ $c->created_at->format('d M Y H:i') }}</div>
            <div>
                <span class="badge bg-secondary me-1">{{ ucwords(str_replace('_',' ',$c->remarks)) }}</span>
                <strong class="small">{{ $c->employee?->full_name ?? 'System' }}</strong>
                <div class="text-muted small">{{ $c->comment }}</div>
            </div>
        </div>
        @empty
        <p class="text-muted small mb-0">No comments yet.</p>
        @endforelse
    </div>
</div>

{{-- Generate Modal --}}
<div class="modal fade" id="generateModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><i class="bi bi-lightning-fill me-1 text-warning"></i>Generate Salaries</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="{{ route('payroll.sheets.generate', $sheet) }}">
                @csrf
                <div class="modal-body">
                    <p class="small text-muted">This will compute salaries for all active employees for the period
                        <strong>{{ $sheet->payroll_from_date }} — {{ $sheet->payroll_to_date }}</strong>.
                        Existing records will be overwritten.
                    </p>
                    <div class="mb-3">
                        <label class="form-label">Confirm your password</label>
                        <input name="password" type="password" class="form-control" placeholder="Enter password" required autofocus>
                        @error('password')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-lightning-fill me-1"></i> Generate
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
