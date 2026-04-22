@extends('layouts.app')
@section('title',$supplier->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.suppliers.index') }}">Suppliers</a></li>
    <li class="breadcrumb-item active">{{ $supplier->name }}</li>
@endsection

@section('content')
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $supplier->name }}</h5>
        <p class="page-subtitle text-muted">{{ $supplier->code ?? 'Supplier' }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('bills.create') }}?supplier_id={{ $supplier->id }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-plus me-1"></i>New Bill</a>
        <a href="{{ route('bills.suppliers.edit',$supplier) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Contact Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Phone</dt><dd class="col-7">{{ $supplier->phone ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Email</dt><dd class="col-7">{{ $supplier->email ?? '—' }}</dd>
                    <dt class="col-5 text-muted">TIN</dt><dd class="col-7">{{ $supplier->tax_number ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Address</dt><dd class="col-7">{{ $supplier->address ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">
                        @if($supplier->is_active)<span class="badge bg-success-subtle text-success">Active</span>
                        @else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
                    </dd>
                </dl>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Recent Bills</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Bill No.</th><th>Issue Date</th><th class="text-end">Amount</th><th class="text-end">Balance</th><th>Status</th></tr></thead>
                    <tbody>
                    @forelse($supplier->bills as $bill)
                    <tr>
                        <td><a href="{{ route('bills.show',$bill) }}">{{ $bill->bill_no ?? 'BILL-'.$bill->id }}</a></td>
                        <td>{{ $bill->issue_date?->format('d M Y') }}</td>
                        <td class="text-end">{{ number_format($bill->total_amount,2) }}</td>
                        <td class="text-end">{{ number_format($bill->balance,2) }}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($bill->status) }}</span></td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">No bills yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
