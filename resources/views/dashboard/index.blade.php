@extends('layouts.app')
@section('title','Home')

@section('breadcrumb')
    <li class="breadcrumb-item active">Home</li>
@endsection

@section('content')

{{-- Welcome bar --}}
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="page-title mb-0">Welcome back, {{ auth()->user()->name }} 👋</h5>
        <p class="page-subtitle mb-0">{{ now()->format('l, d F Y') }} &nbsp;·&nbsp; {{ $currentCompany?->name ?? 'Smart ERP' }}</p>
    </div>
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
