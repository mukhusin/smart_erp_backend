@extends('layouts.app')
@section('title','Purchase Request')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('procurement.requests.index') }}">Purchase Requests</a></li>
    <li class="breadcrumb-item active">{{ $pr->pr_number ?? 'PR-'.$pr->id }}</li>
@endsection

@section('content')
@php $statusColors = ['draft'=>'secondary','pending'=>'warning','approved'=>'success','rejected'=>'danger','ordered'=>'primary']; @endphp
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $pr->pr_number ?? 'Purchase Request #'.$pr->id }}</h5>
        <p class="page-subtitle text-muted">{{ $pr->date?->format('d M Y') }}</p>
    </div>
    <div class="d-flex gap-2">
        @if($pr->status === 'pending')
        <form method="POST" action="{{ route('procurement.requests.approve',$pr) }}">@csrf<button class="btn btn-sm btn-success"><i class="bi bi-check-circle me-1"></i>Approve</button></form>
        <form method="POST" action="{{ route('procurement.requests.reject',$pr) }}">@csrf<button class="btn btn-sm btn-danger"><i class="bi bi-x-circle me-1"></i>Reject</button></form>
        @endif
        @if($pr->status === 'approved')
        <a href="{{ route('procurement.orders.create') }}?pr_id={{ $pr->id }}" class="btn btn-sm btn-primary"><i class="bi bi-cart-check me-1"></i>Create PO</a>
        @endif
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Details</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><span class="badge bg-{{ $statusColors[$pr->status] ?? 'secondary' }}">{{ ucfirst($pr->status) }}</span></dd>
                    <dt class="col-5 text-muted">Department</dt><dd class="col-7">{{ $pr->department?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Requested By</dt><dd class="col-7">{{ $pr->requester?->full_name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Date</dt><dd class="col-7">{{ $pr->date?->format('d M Y') }}</dd>
                </dl>
                @if($pr->notes)<hr><p class="small text-muted mb-0">{{ $pr->notes }}</p>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Requested Items</div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>#</th><th>Item</th><th class="text-center">Qty</th><th>Unit</th><th class="text-end">Est. Cost</th><th>Notes</th></tr></thead>
                    <tbody>
                    @foreach($pr->items as $i => $item)
                    <tr>
                        <td>{{ $i+1 }}</td>
                        <td class="fw-semibold">{{ $item->item_name }}</td>
                        <td class="text-center">{{ $item->quantity }}</td>
                        <td>{{ $item->unit ?? '—' }}</td>
                        <td class="text-end">{{ $item->estimated_cost ? number_format($item->estimated_cost,2) : '—' }}</td>
                        <td class="text-muted">{{ $item->notes ?? '—' }}</td>
                    </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
