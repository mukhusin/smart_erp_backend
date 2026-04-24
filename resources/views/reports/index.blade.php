@extends('layouts.app')
@section('title', 'Reports')

@section('breadcrumb')
    <li class="breadcrumb-item active">Reports</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h5 class="page-title mb-0">Reports</h5>
        <p class="page-subtitle mb-0">Generate and export HR & operational reports</p>
    </div>
</div>

<div class="row g-4">
    {{-- Employee Report --}}
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('reports.employees') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 p-4" style="border-top:3px solid #0d6efd !important">
                <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                     style="width:52px;height:52px;background:#e7f0ff">
                    <i class="bi bi-people-fill" style="color:#0d6efd;font-size:1.4rem"></i>
                </div>
                <h6 class="fw-bold mb-1">Employee Report</h6>
                <p class="text-muted small mb-0">Active employees filtered by department, gender, contract type and employment status.</p>
            </div>
        </a>
    </div>

    {{-- Leave Report --}}
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('reports.leaves') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 p-4" style="border-top:3px solid #ffc107 !important">
                <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                     style="width:52px;height:52px;background:#fff8e1">
                    <i class="bi bi-calendar-x" style="color:#ffc107;font-size:1.4rem"></i>
                </div>
                <h6 class="fw-bold mb-1">Leave Report</h6>
                <p class="text-muted small mb-0">Leave requests by department, type, status and date range with summary totals.</p>
            </div>
        </a>
    </div>

    {{-- Contract Report --}}
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('reports.contracts') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 p-4" style="border-top:3px solid #dc3545 !important">
                <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                     style="width:52px;height:52px;background:#fde8ea">
                    <i class="bi bi-file-earmark-text" style="color:#dc3545;font-size:1.4rem"></i>
                </div>
                <h6 class="fw-bold mb-1">Contract Report</h6>
                <p class="text-muted small mb-0">Expiring, expired or all active employee contracts with days remaining.</p>
            </div>
        </a>
    </div>

    {{-- Attendance Report --}}
    <div class="col-md-6 col-xl-3">
        <a href="{{ route('reports.attendance') }}" class="text-decoration-none">
            <div class="card border-0 shadow-sm h-100 p-4" style="border-top:3px solid #20c997 !important">
                <div class="rounded-circle d-flex align-items-center justify-content-center mb-3"
                     style="width:52px;height:52px;background:#e0f8f1">
                    <i class="bi bi-fingerprint" style="color:#20c997;font-size:1.4rem"></i>
                </div>
                <h6 class="fw-bold mb-1">Attendance Summary</h6>
                <p class="text-muted small mb-0">Attendance by employee and department for a selected date range.</p>
            </div>
        </a>
    </div>
</div>
@endsection
