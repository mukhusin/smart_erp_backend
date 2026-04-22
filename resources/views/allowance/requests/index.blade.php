@extends('layouts.app')
@section('title','Employee Allowances')
@section('breadcrumb')<li class="breadcrumb-item active">Emp Allowances</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Employee Allowances</h5>
    <a href="{{ route('allowance.requests.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New</a>
</div>
<form class="row g-2 mb-3" method="GET">
    <div class="col-md-3"><select name="employee_id" class="form-select form-select-sm"><option value="">All Employees</option>@foreach($employees as $e)<option value="{{ $e->id }}" {{ request('employee_id')==$e->id?'selected':'' }}>{{ $e->full_name }}</option>@endforeach</select></div>
    <div class="col-md-2"><select name="status" class="form-select form-select-sm"><option value="">All Status</option>@foreach(['pending','approved','rejected'] as $s)<option value="{{ $s }}" {{ request('status')==$s?'selected':'' }}>{{ ucfirst($s) }}</option>@endforeach</select></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filter</button></div>
</form>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>#</th><th>Employee</th><th>Category</th><th>Duration</th><th>Items</th><th>Amount</th><th>Period</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($allowances as $a)
                <tr>
                    <td>{{ $allowances->firstItem()+$loop->index }}</td>
                    <td>{{ $a->employee?->full_name }}</td>
                    <td>{{ $a->category }}</td>
                    <td>{{ $a->duration }}</td>
                    <td>{{ $a->items }}</td>
                    <td>{{ number_format($a->amount, 2) }}</td>
                    <td>{{ $a->start_date }} – {{ $a->end_date }}</td>
                    <td>@php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$a->status]??'secondary'@endphp<span class="badge bg-{{ $sc }}">{{ ucfirst($a->status) }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('allowance.requests.show', $a) }}" class="btn btn-sm btn-outline-info"><i class="bi bi-eye"></i></a>
                        <a href="{{ route('allowance.requests.edit', $a) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('allowance.requests.destroy', $a) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="9" class="text-center text-muted py-4">No allowances found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($allowances->hasPages())<div class="card-footer">{{ $allowances->links() }}</div>@endif
</div>
@endsection
