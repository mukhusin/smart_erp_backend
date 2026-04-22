@extends('layouts.app')
@section('title','Contract Types')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Contract Types</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Contract Types</h5><p class="page-subtitle">Nature of employment contracts</p></div>
    <a href="{{ route('hr.contract-types.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Type</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr><th>#</th><th>Name</th><th>Code</th><th>Type</th><th>Features</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($types as $i => $t)
                <tr>
                    <td>{{ $i+1 }}</td>
                    <td class="fw-semibold">{{ $t->contract_name }}</td>
                    <td><span class="badge bg-secondary">{{ $t->code }}</span></td>
                    <td>{{ $t->employment_type }}</td>
                    <td>
                        @if($t->has_payroll)<span class="badge bg-primary-subtle text-primary me-1">Payroll</span>@endif
                        @if($t->has_annual_leave)<span class="badge bg-success-subtle text-success me-1">Leave</span>@endif
                        @if($t->has_overtime)<span class="badge bg-warning-subtle text-warning me-1">OT</span>@endif
                        @if($t->has_allowance)<span class="badge bg-info-subtle text-info me-1">Allowance</span>@endif
                    </td>
                    <td>
                        @if($t->is_active)<span class="badge bg-success-subtle text-success">Active</span>
                        @else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
                    </td>
                    <td class="text-end">
                        <a href="{{ route('hr.contract-types.edit',$t) }}" class="btn btn-sm btn-light"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('hr.contract-types.destroy',$t) }}" class="d-inline" onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-light text-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No contract types.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
