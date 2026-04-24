@extends('layouts.app')
@section('title','Home')

@section('breadcrumb')
    <li class="breadcrumb-item active">Home</li>
@endsection

@section('content')

{{-- Welcome bar --}}
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-0">Welcome back, {{ auth()->user()->name }} 👋</h5>
        <p class="page-subtitle mb-0">{{ now()->format('l, d F Y') }} &nbsp;·&nbsp; {{ $currentCompany?->name ?? 'Smart ERP' }}</p>
    </div>
    <a href="{{ route('self-service.dashboard') }}" class="btn btn-outline-primary btn-sm">
        <i class="bi bi-person-circle me-1"></i> My Portal
    </a>
</div>

{{-- ── KPI BAR ──────────────────────────────────────────────── --}}
<div class="row g-3 mb-4">
    @php
        $kpiCards = [
            ['label' => 'Active Employees',   'value' => $kpis['employees'],      'icon' => 'bi-people-fill',       'color' => 'primary',  'route' => 'hr.employees.index'],
            ['label' => 'Pending Leaves',      'value' => $kpis['pending_leaves'], 'icon' => 'bi-hourglass-split',   'color' => 'warning',  'route' => 'leave.requests.index', 'route_params' => ['status' => 'pending']],
            ['label' => 'On Leave Now',        'value' => $kpis['on_leave'],       'icon' => 'bi-person-slash',      'color' => 'info',     'route' => 'leave.requests.index', 'route_params' => ['status' => 'active']],
            ['label' => 'Unpaid Bills',        'value' => $kpis['unpaid_bills'],   'icon' => 'bi-receipt-cutoff',    'color' => 'danger',   'route' => 'bills.index'],
            ['label' => 'Pending PRs',         'value' => $kpis['pending_prs'],    'icon' => 'bi-box-seam',          'color' => 'secondary','route' => 'procurement.requests.index'],
            ['label' => 'Low Stock Items',     'value' => $kpis['low_stock_items'],'icon' => 'bi-exclamation-triangle','color' => 'orange', 'route' => 'inventory.items.index', 'route_params' => ['low_stock' => 1]],
        ];
    @endphp
    @foreach($kpiCards as $card)
    @php
        $colorMap = ['primary'=>'#0d6efd','warning'=>'#ffc107','info'=>'#0dcaf0','danger'=>'#dc3545','secondary'=>'#6c757d','orange'=>'#fd7e14'];
        $lightMap = ['primary'=>'#e7f0ff','warning'=>'#fff8e1','info'=>'#e0f9ff','danger'=>'#fde8ea','secondary'=>'#f0f0f0','orange'=>'#fff0e0'];
        $col = $card['color'];
    @endphp
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route($card['route'], $card['route_params'] ?? []) }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 text-center py-3 px-2"
                 style="border-top: 3px solid {{ $colorMap[$col] ?? '#6c757d' }} !important">
                <div class="mx-auto mb-2 rounded-circle d-flex align-items-center justify-content-center"
                     style="width:44px;height:44px;background:{{ $lightMap[$col] ?? '#f0f0f0' }}">
                    <i class="bi {{ $card['icon'] }}" style="color:{{ $colorMap[$col] ?? '#6c757d' }};font-size:1.25rem"></i>
                </div>
                <div class="fw-bold fs-4" style="color:{{ $colorMap[$col] ?? '#6c757d' }}">{{ $card['value'] }}</div>
                <div class="text-muted small lh-sm">{{ $card['label'] }}</div>
            </div>
        </a>
    </div>
    @endforeach
</div>

{{-- ── MODULE GRID ─────────────────────────────────────────── --}}
@php
    $categories = $modules->groupBy('category');
@endphp

@foreach($categories as $category => $catModules)
<div class="mb-4">
    <p class="text-uppercase text-muted fw-semibold small mb-2" style="letter-spacing:.08em">
        {{ $category }}
    </p>

    <div class="row g-3">
    @foreach($catModules as $mod)
        @php
            $locked  = !$mod->has_access;
            $href    = $locked ? '#' : ($mod->route_name ? route($mod->route_name) : '#');
            $colorMap = [
                'primary'   => ['bg'=>'#0d6efd','light'=>'#e7f0ff'],
                'info'      => ['bg'=>'#0dcaf0','light'=>'#e0f9ff'],
                'success'   => ['bg'=>'#198754','light'=>'#e6f4ee'],
                'warning'   => ['bg'=>'#ffc107','light'=>'#fff8e1'],
                'danger'    => ['bg'=>'#dc3545','light'=>'#fde8ea'],
                'secondary' => ['bg'=>'#6c757d','light'=>'#f0f0f0'],
                'dark'      => ['bg'=>'#212529','light'=>'#e9ecef'],
                'teal'      => ['bg'=>'#20c997','light'=>'#e0f8f1'],
                'orange'    => ['bg'=>'#fd7e14','light'=>'#fff0e0'],
            ];
            $c = $colorMap[$mod->color] ?? $colorMap['primary'];
        @endphp

        <div class="col-6 col-md-4 col-lg-3 col-xl-2">
            <a href="{{ $href }}"
               class="module-card {{ $locked ? 'module-locked' : '' }}"
               @if($locked) title="Not available for your account" data-bs-toggle="tooltip" @endif>

                {{-- Icon circle --}}
                <div class="module-icon" style="background:{{ $locked ? '#e9ecef' : $c['light'] }}">
                    <i class="bi {{ $mod->icon }}"
                       style="color:{{ $locked ? '#adb5bd' : $c['bg'] }}; font-size:1.6rem"></i>
                </div>

                {{-- Name --}}
                <div class="module-name {{ $locked ? 'text-muted' : '' }}">
                    {{ $mod->name }}
                </div>

                {{-- Description --}}
                <div class="module-desc">{{ Str::limit($mod->description, 60) }}</div>

                {{-- Lock badge --}}
                @if($locked)
                    <div class="module-lock-badge">
                        <i class="bi bi-lock-fill"></i> Not Available
                    </div>
                @endif

            </a>
        </div>
    @endforeach
    </div>
</div>
@endforeach

@endsection

@push('styles')
<style>
.module-card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    padding: 1.25rem .75rem 1rem;
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 14px;
    text-decoration: none;
    color: #212529;
    transition: transform .15s, box-shadow .15s, border-color .15s;
    min-height: 148px;
    position: relative;
    overflow: hidden;
}
.module-card:hover:not(.module-locked) {
    transform: translateY(-3px);
    box-shadow: 0 8px 24px rgba(0,0,0,.1);
    border-color: #c0d0f0;
    color: #212529;
}
.module-locked {
    opacity: .55;
    cursor: not-allowed;
    background: #f8f9fa;
}
.module-icon {
    width: 56px;
    height: 56px;
    border-radius: 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: .65rem;
    flex-shrink: 0;
}
.module-name {
    font-weight: 600;
    font-size: .88rem;
    line-height: 1.2;
    margin-bottom: .3rem;
}
.module-desc {
    font-size: .72rem;
    color: #6c757d;
    line-height: 1.4;
}
.module-lock-badge {
    position: absolute;
    top: 8px;
    right: 8px;
    font-size: .65rem;
    background: #dee2e6;
    color: #6c757d;
    border-radius: 20px;
    padding: .15rem .45rem;
}
</style>
@endpush

@push('scripts')
<script>
// Bootstrap tooltips on locked modules
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { placement: 'top' });
});
</script>
@endpush
