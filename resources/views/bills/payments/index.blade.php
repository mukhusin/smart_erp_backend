@extends('layouts.app')
@section('title','Payments')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Payments</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Bill Payments</h5>
        <p class="page-subtitle">All recorded bill payments</p>
    </div>
    <a href="{{ route('bills.payments.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> Record Payment
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Bill No.</th>
                    <th>Supplier</th>
                    <th>Method</th>
                    <th>Reference</th>
                    <th class="text-end">Amount</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($payments as $pmt)
            <tr>
                <td>{{ $pmt->payment_date?->format('d M Y') }}</td>
                <td><a href="{{ route('bills.show',$pmt->bill) }}">{{ $pmt->bill?->bill_no ?? 'BILL-'.$pmt->bill_id }}</a></td>
                <td>{{ $pmt->bill?->supplier?->name }}</td>
                <td>{{ ucwords(str_replace('_',' ',$pmt->payment_method)) }}</td>
                <td>{{ $pmt->reference ?? '—' }}</td>
                <td class="text-end fw-semibold">{{ number_format($pmt->amount,2) }}</td>
                <td class="text-end">
                    <form method="POST" action="{{ route('bills.payments.destroy',$pmt) }}" onsubmit="return confirm('Reverse this payment?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-sm btn-outline-danger" title="Reverse"><i class="bi bi-arrow-counterclockwise"></i></button>
                    </form>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No payments recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($payments->hasPages())
    <div class="card-footer bg-white">{{ $payments->links() }}</div>
    @endif
</div>
@endsection
