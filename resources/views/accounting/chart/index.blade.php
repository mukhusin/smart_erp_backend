@extends('layouts.app')
@section('title','Chart of Accounts')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Chart of Accounts</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Chart of Accounts</h5>
        <p class="page-subtitle">Define your account structure for double-entry bookkeeping</p>
    </div>
    <a href="{{ route('accounting.chart.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Account
    </a>
</div>

@php
    $types = ['asset'=>'Assets','liability'=>'Liabilities','equity'=>'Equity','revenue'=>'Revenue','expense'=>'Expenses'];
    $typeColors = ['asset'=>'primary','liability'=>'danger','equity'=>'success','revenue'=>'info','expense'=>'warning'];
    $grouped = $accounts->groupBy('type');
@endphp

@foreach($types as $type => $label)
    @if($grouped->has($type))
    <div class="card mb-3">
        <div class="card-header bg-white py-2 d-flex align-items-center gap-2">
            <span class="badge bg-{{ $typeColors[$type] }}-subtle text-{{ $typeColors[$type] }} fs-6">{{ $label }}</span>
            <span class="text-muted small">{{ $grouped[$type]->count() }} accounts</span>
        </div>
        <div class="card-body p-0">
            <table class="table table-hover table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Code</th>
                        <th>Account Name</th>
                        <th>Parent</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                @foreach($grouped[$type] as $account)
                <tr>
                    <td><code>{{ $account->code ?? '—' }}</code></td>
                    <td class="fw-semibold {{ $account->parent_id ? 'ps-4' : '' }}">
                        @if($account->parent_id)<i class="bi bi-arrow-return-right text-muted me-1"></i>@endif
                        {{ $account->name }}
                    </td>
                    <td>{{ $account->parent?->name ?? '—' }}</td>
                    <td>
                        @if($account->is_active)
                            <span class="badge bg-success-subtle text-success">Active</span>
                        @else
                            <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                        @endif
                    </td>
                    <td class="text-end">
                        <div class="dropdown">
                            <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="{{ route('accounting.chart.show',$account) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                                <li><a class="dropdown-item" href="{{ route('accounting.chart.edit',$account) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <form method="POST" action="{{ route('accounting.chart.destroy',$account) }}" onsubmit="return confirm('Delete this account?')">
                                        @csrf @method('DELETE')
                                        <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                    </form>
                                </li>
                            </ul>
                        </div>
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
@endforeach

@if($accounts->isEmpty())
<div class="card"><div class="card-body text-center text-muted py-5">
    <i class="bi bi-list-nested fs-1 d-block mb-2 opacity-25"></i>
    No accounts yet. <a href="{{ route('accounting.chart.create') }}">Create your first account</a>.
</div></div>
@endif
@endsection
