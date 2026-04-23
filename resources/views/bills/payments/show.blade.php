@extends('layouts.app')
@section('title','Payment Detail')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('bills.payments.index') }}">Payments</a></li>
    <li class="breadcrumb-item active">Detail</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-credit-card-2-front me-2 text-success"></i>Payment Detail</span>
        <span class="badge bg-success fs-6">{{ number_format($payment->amount, 2) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Bill</dt>
            <dd class="col-sm-8">
                <a href="{{ route('bills.show', $payment->bill) }}" class="fw-semibold text-decoration-none">
                    {{ $payment->bill?->bill_number ?? 'Bill #'.$payment->bill_id }}
                </a>
            </dd>

            <dt class="col-sm-4 text-muted">Supplier</dt>
            <dd class="col-sm-8">{{ $payment->bill?->supplier?->name ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Payment Date</dt>
            <dd class="col-sm-8">{{ $payment->payment_date?->format('d M Y') }}</dd>

            <dt class="col-sm-4 text-muted">Amount</dt>
            <dd class="col-sm-8 fw-semibold text-success fs-5">{{ number_format($payment->amount, 2) }}</dd>

            <dt class="col-sm-4 text-muted">Method</dt>
            <dd class="col-sm-8"><span class="badge bg-secondary-subtle text-secondary">{{ ucwords(str_replace('_',' ',$payment->method)) }}</span></dd>

            <dt class="col-sm-4 text-muted">Reference</dt>
            <dd class="col-sm-8">{{ $payment->reference ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Notes</dt>
            <dd class="col-sm-8">{{ $payment->notes ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Recorded At</dt>
            <dd class="col-sm-8">{{ $payment->created_at?->format('d M Y H:i') }}</dd>
        </dl>
    </div>
    <div class="card-footer bg-white d-flex gap-2">
        <a href="{{ route('bills.payments.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <a href="{{ route('bills.show', $payment->bill) }}" class="btn btn-outline-primary"><i class="bi bi-file-text me-1"></i>View Bill</a>
        <form method="POST" action="{{ route('bills.payments.destroy', $payment) }}" class="ms-auto" onsubmit="return confirm('Reverse this payment?')">
            @csrf @method('DELETE')
            <button class="btn btn-outline-danger"><i class="bi bi-arrow-counterclockwise me-1"></i>Reverse</button>
        </form>
    </div>
</div>
</div></div>
@endsection
