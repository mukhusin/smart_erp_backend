@extends('layouts.app')
@section('title','Bills')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Bills</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Bills</h5>
        <p class="page-subtitle">Vendor invoices and payables</p>
    </div>
    <a href="{{ route('bills.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Bill
    </a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Supplier</label>
                <select name="supplier_id" class="form-select form-select-sm">
                    <option value="">All Suppliers</option>
                    @foreach($suppliers as $s)
                    <option value="{{ $s->id }}" {{ request('supplier_id')==$s->id?'selected':'' }}>{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    @foreach(['draft','open','partial','paid','overdue','cancelled'] as $st)
                    <option value="{{ $st }}" {{ request('status')==$st?'selected':'' }}>{{ ucfirst($st) }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Bill No.</th>
                    <th>Supplier</th>
                    <th>Issue Date</th>
                    <th>Due Date</th>
                    <th class="text-end">Amount</th>
                    <th class="text-end">Paid</th>
                    <th class="text-end">Balance</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($bills as $bill)
            @php
                $statusColors = ['draft'=>'secondary','open'=>'primary','partial'=>'warning','paid'=>'success','overdue'=>'danger','cancelled'=>'secondary'];
                $sc = $statusColors[$bill->status] ?? 'secondary';
            @endphp
            <tr>
                <td><a href="{{ route('bills.show',$bill) }}" class="fw-semibold">{{ $bill->bill_no ?? 'BILL-'.$bill->id }}</a></td>
                <td>{{ $bill->supplier?->name }}</td>
                <td>{{ $bill->issue_date?->format('d M Y') }}</td>
                <td>
                    {{ $bill->due_date?->format('d M Y') ?? '—' }}
                    @if($bill->due_date && $bill->due_date->isPast() && !in_array($bill->status,['paid','cancelled']))
                        <span class="badge bg-danger-subtle text-danger ms-1">Overdue</span>
                    @endif
                </td>
                <td class="text-end">{{ number_format($bill->total_amount,2) }}</td>
                <td class="text-end">{{ number_format($bill->paid_amount,2) }}</td>
                <td class="text-end fw-semibold {{ $bill->balance > 0 ? 'text-danger':'' }}">{{ number_format($bill->balance,2) }}</td>
                <td><span class="badge bg-{{ $sc }}-subtle text-{{ $sc }}">{{ ucfirst($bill->status) }}</span></td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('bills.show',$bill) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                            @if(!in_array($bill->status,['paid','cancelled']))
                            <li><a class="dropdown-item" href="{{ route('bills.edit',$bill) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li><a class="dropdown-item text-success" href="{{ route('bills.payments.create') }}?bill_id={{ $bill->id }}"><i class="bi bi-cash me-2"></i>Record Payment</a></li>
                            @endif
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No bills found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($bills->hasPages())
    <div class="card-footer bg-white">{{ $bills->links() }}</div>
    @endif
</div>
@endsection
