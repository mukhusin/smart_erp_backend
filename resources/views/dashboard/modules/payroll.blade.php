@extends('layouts.app')
@section('title','Payroll Dashboard')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Payroll</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-cash-stack me-2 text-success"></i>Payroll</h4>
        <p class="text-muted small mb-0">{{ $currentCompany?->name }} &nbsp;·&nbsp; {{ now()->format('F Y') }}</p>
    </div>
    <a href="{{ route('payroll.sheets.create') }}" class="btn btn-success btn-sm">
        <i class="bi bi-plus me-1"></i> New Sheet
    </a>
</div>
<div class="row g-3 mb-4">
    @php $cards = [
        ['label'=>'Total Sheets',    'value'=> $stats['total_sheets'],  'icon'=>'bi-file-earmark-bar-graph','color'=>'#0d6efd','light'=>'#e7f0ff'],
        ['label'=>'Draft Sheets',    'value'=> $stats['draft'],         'icon'=>'bi-pencil-square',         'color'=>'#ffc107','light'=>'#fff8e1'],
        ['label'=>'Pending Adjustments','value'=> $stats['adjustments'],'icon'=>'bi-pencil',                'color'=>'#dc3545','light'=>'#fde8ea'],
    ]; @endphp
    @foreach($cards as $c)
    <div class="col-6 col-md-4">
        <div class="card border-0 shadow-sm h-100 text-center py-3" style="border-top:3px solid {{ $c['color'] }} !important">
            <div class="mx-auto mb-2 rounded-circle d-flex align-items-center justify-content-center"
                 style="width:48px;height:48px;background:{{ $c['light'] }}">
                <i class="bi {{ $c['icon'] }}" style="color:{{ $c['color'] }};font-size:1.4rem"></i>
            </div>
            <div class="fw-bold fs-3" style="color:{{ $c['color'] }}">{{ $c['value'] }}</div>
            <div class="text-muted small">{{ $c['label'] }}</div>
        </div>
    </div>
    @endforeach
</div>
@if($recent->isNotEmpty())
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">Recent Payroll Sheets</div>
    <div class="table-responsive">
        <table class="table table-sm mb-0">
            <thead class="table-light"><tr><th>Month</th><th>Status</th><th>Created</th><th></th></tr></thead>
            <tbody>
                @foreach($recent as $sheet)
                <tr>
                    <td>{{ $sheet->payroll_month ?? '—' }}</td>
                    <td><span class="badge bg-{{ $sheet->status === 'processed' ? 'success' : ($sheet->status === 'draft' ? 'warning text-dark' : 'secondary') }}">{{ ucfirst($sheet->status) }}</span></td>
                    <td class="text-muted small">{{ $sheet->created_at->format('d M Y') }}</td>
                    <td><a href="{{ route('payroll.sheets.show', $sheet) }}" class="btn btn-xs btn-outline-secondary btn-sm py-0 px-1">View</a></td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold">Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="{{ route('payroll.sheets.index') }}" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-bar-graph me-1"></i>Payroll Sheets</a>
        <a href="{{ route('payroll.salary-adjustments.index') }}" class="btn btn-outline-warning btn-sm"><i class="bi bi-pencil-square me-1"></i>Salary Adjustments</a>
        <a href="{{ route('payroll.deductions.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-dash-circle me-1"></i>Deductions</a>
    </div>
</div>
@endsection
