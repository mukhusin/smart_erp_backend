@extends('layouts.app')
@section('title','Daily Allowances')

@section('breadcrumb')
    <li class="breadcrumb-item active">Daily Allowances</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Daily Allowances</h5>
    <a href="{{ route('allowance.daily.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Daily Allowance
    </a>
</div>

<form class="row g-2 mb-3" method="GET">
    <div class="col-md-4">
        <select name="employee_id" class="form-select form-select-sm">
            <option value="">All Employees</option>
            @foreach($employees as $emp)
                <option value="{{ $emp->id }}" {{ request('employee_id') == $emp->id ? 'selected' : '' }}>
                    {{ $emp->full_name }}
                </option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <select name="status" class="form-select form-select-sm">
            <option value="">All Status</option>
            @foreach(['pending','approved','rejected'] as $s)
                <option value="{{ $s }}" {{ request('status') == $s ? 'selected' : '' }}>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>

<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th><th>Employee</th><th>Category</th><th>Items</th>
                    <th>Amount/Item</th><th>Total</th><th>Status</th><th></th>
                </tr>
            </thead>
            <tbody>
                @forelse($allowances as $a)
                <tr>
                    <td>{{ $allowances->firstItem() + $loop->index }}</td>
                    <td>{{ $a->employee?->full_name }}</td>
                    <td>{{ $a->category?->category }}</td>
                    <td>{{ $a->number_of_items }}</td>
                    <td>{{ number_format($a->amount_per_item, 2) }}</td>
                    <td>{{ number_format($a->amount, 2) }}</td>
                    <td>
                        @php $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$a->status] ?? 'secondary' @endphp
                        <span class="badge bg-{{ $sc }}">{{ ucfirst($a->status) }}</span>
                    </td>
                    <td class="text-end">
                        <a href="{{ route('allowance.daily.show', $a) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('allowance.daily.edit', $a) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('allowance.daily.destroy', $a) }}" class="d-inline"
                              onsubmit="return confirm('Delete?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No daily allowances found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($allowances->hasPages())
    <div class="card-footer">{{ $allowances->withQueryString()->links() }}</div>
    @endif
</div>
@endsection
