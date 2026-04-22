@extends('layouts.app')
@section('title','Bill')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.index') }}">Bills</a></li>
    <li class="breadcrumb-item active">{{ $bill->bill_no ?? 'BILL-'.$bill->id }}</li>
@endsection

@section('content')
@php $statusColors = ['draft'=>'secondary','open'=>'primary','partial'=>'warning','paid'=>'success','overdue'=>'danger','cancelled'=>'secondary']; @endphp
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $bill->bill_no ?? 'Bill #'.$bill->id }}</h5>
        <p class="page-subtitle text-muted">{{ $bill->supplier?->name }}</p>
    </div>
    <div class="d-flex gap-2">
        @if(!in_array($bill->status,['paid','cancelled']))
        <a href="{{ route('bills.payments.create') }}?bill_id={{ $bill->id }}" class="btn btn-sm btn-success"><i class="bi bi-cash me-1"></i>Record Payment</a>
        <a href="{{ route('bills.edit',$bill) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Bill Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><span class="badge bg-{{ $statusColors[$bill->status] ?? 'secondary' }}">{{ ucfirst($bill->status) }}</span></dd>
                    <dt class="col-5 text-muted">Supplier</dt><dd class="col-7">{{ $bill->supplier?->name }}</dd>
                    <dt class="col-5 text-muted">Issue Date</dt><dd class="col-7">{{ $bill->issue_date?->format('d M Y') }}</dd>
                    <dt class="col-5 text-muted">Due Date</dt><dd class="col-7">{{ $bill->due_date?->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Subtotal</dt><dd class="col-7">{{ number_format($bill->subtotal,2) }}</dd>
                    <dt class="col-5 text-muted">Tax</dt><dd class="col-7">{{ number_format($bill->tax_amount,2) }}</dd>
                    <dt class="col-5 text-muted fw-bold">Total</dt><dd class="col-7 fw-bold">{{ number_format($bill->total_amount,2) }}</dd>
                    <dt class="col-5 text-muted">Paid</dt><dd class="col-7 text-success">{{ number_format($bill->paid_amount,2) }}</dd>
                    <dt class="col-5 text-muted fw-bold">Balance</dt><dd class="col-7 fw-bold {{ $bill->balance > 0 ? 'text-danger':'' }}">{{ number_format($bill->balance,2) }}</dd>
                </dl>
                @if($bill->notes)
                <hr>
                <p class="small text-muted mb-0">{{ $bill->notes }}</p>
                @endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white py-2 d-flex justify-content-between fw-semibold">
                <span>Payment History</span>
                <a href="{{ route('bills.payments.create') }}?bill_id={{ $bill->id }}" class="btn btn-sm btn-outline-success"><i class="bi bi-plus me-1"></i>Add Payment</a>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Method</th><th>Reference</th><th class="text-end">Amount</th><th></th></tr></thead>
                    <tbody>
                    @forelse($bill->payments as $pmt)
                    <tr>
                        <td>{{ $pmt->payment_date?->format('d M Y') }}</td>
                        <td>{{ ucwords(str_replace('_',' ',$pmt->payment_method)) }}</td>
                        <td>{{ $pmt->reference ?? '—' }}</td>
                        <td class="text-end fw-semibold">{{ number_format($pmt->amount,2) }}</td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('bills.payments.destroy',$pmt) }}" onsubmit="return confirm('Reverse this payment?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-arrow-counterclockwise"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">No payments yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
