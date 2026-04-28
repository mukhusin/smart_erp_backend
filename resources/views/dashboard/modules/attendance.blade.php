@extends('layouts.app')
@section('title','Attendance Dashboard')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Attendance</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h4 class="page-title mb-0"><i class="bi bi-fingerprint me-2 text-secondary"></i>Attendance</h4>
        <p class="text-muted small mb-0">{{ $currentCompany?->name }} &nbsp;·&nbsp; {{ now()->format('F Y') }}</p>
    </div>
</div>
<div class="row g-3 mb-4">
    @php $cards = [
        ['label'=>'Today\'s Records',    'value'=> $stats['today'],      'icon'=>'bi-calendar-day',    'color'=>'#0d6efd','light'=>'#e7f0ff'],
        ['label'=>'This Month Records',  'value'=> $stats['this_month'], 'icon'=>'bi-calendar-month',  'color'=>'#198754','light'=>'#e6f4ee'],
        ['label'=>'Last Month Records',  'value'=> $stats['last_month'], 'icon'=>'bi-calendar3',       'color'=>'#6c757d','light'=>'#f0f0f0'],
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
<div class="card border-0 shadow-sm">
    <div class="card-header bg-transparent fw-semibold">Quick Actions</div>
    <div class="card-body d-flex flex-wrap gap-2">
        <a href="{{ route('attendance.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-table me-1"></i>Transactions</a>
        <a href="{{ route('attendance.claims.index') }}" class="btn btn-outline-info btn-sm"><i class="bi bi-patch-question me-1"></i>Claims</a>
    </div>
</div>
@endsection
