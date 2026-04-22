@extends('layouts.app')
@section('title','Purchase Order')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('procurement.orders.index') }}">Purchase Orders</a></li>
    <li class="breadcrumb-item active">{{ $order->po_number ?? 'PO-'.$order->id }}</li>
@endsection

@section('content')
@php $statusColors = ['draft'=>'secondary','sent'=>'info','confirmed'=>'primary','received'=>'success','cancelled'=>'danger']; @endphp
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $order->po_number ?? 'Purchase Order #'.$order->id }}</h5>
        <p class="page-subtitle text-muted">{{ $order->date?->format('d M Y') }}</p>
    </div>
    @if($order->status === 'draft')
    <a href="{{ route('procurement.orders.edit',$order) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    @endif
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Order Details</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><span class="badge bg-{{ $statusColors[$order->status] ?? 'secondary' }}">{{ ucfirst($order->status) }}</span></dd>
                    <dt class="col-5 text-muted">Supplier</dt><dd class="col-7">{{ $order->supplier?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Linked PR</dt>
                    <dd class="col-7">
                        @if($order->purchaseRequest)
                            <a href="{{ route('procurement.requests.show',$order->purchaseRequest) }}">{{ $order->purchaseRequest->pr_number ?? 'PR-'.$order->purchase_request_id }}</a>
                        @else —
                        @endif
                    </dd>
                    <dt class="col-5 text-muted fw-bold">Total</dt><dd class="col-7 fw-bold">{{ number_format($order->total_amount,2) }}</dd>
                </dl>
                @if($order->notes)<hr><p class="small text-muted mb-0">{{ $order->notes }}</p>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Order Items</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Item</th><th class="text-center">Qty</th><th>Unit</th><th class="text-end">Unit Price</th><th class="text-end">Total</th></tr></thead>
                    <tbody>
                    @foreach($order->items as $i => $item)
                    <tr>
                        <td>{{ $i+1 }}</td>
                        <td class="fw-semibold">{{ $item->item_name }}</td>
                        <td class="text-center">{{ $item->quantity }}</td>
                        <td>{{ $item->unit ?? '—' }}</td>
                        <td class="text-end">{{ number_format($item->unit_price,2) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($item->total_price,2) }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr><td colspan="5" class="text-end">Total</td><td class="text-end">{{ number_format($order->total_amount,2) }}</td></tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
