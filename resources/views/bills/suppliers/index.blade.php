@extends('layouts.app')
@section('title','Suppliers')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Suppliers</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Suppliers</h5>
        <p class="page-subtitle">Manage your vendor and supplier directory</p>
    </div>
    <a href="{{ route('bills.suppliers.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Supplier
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>#</th>
                    <th>Name</th>
                    <th>Code</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th class="text-center">Bills</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($suppliers as $i => $s)
            <tr>
                <td>{{ $i+1 }}</td>
                <td class="fw-semibold">{{ $s->name }}</td>
                <td><code>{{ $s->code ?? '—' }}</code></td>
                <td>{{ $s->phone ?? '—' }}</td>
                <td>{{ $s->email ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-primary">{{ $s->bills_count }}</span></td>
                <td>
                    @if($s->is_active)<span class="badge bg-success-subtle text-success">Active</span>
                    @else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
                </td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('bills.suppliers.show',$s) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                            <li><a class="dropdown-item" href="{{ route('bills.suppliers.edit',$s) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('bills.suppliers.destroy',$s) }}" onsubmit="return confirm('Delete this supplier?')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No suppliers. <a href="{{ route('bills.suppliers.create') }}">Add one</a>.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
