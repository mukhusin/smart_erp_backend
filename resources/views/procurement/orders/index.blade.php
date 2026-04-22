@extends('layouts.app')
@section('title','Purchase Orders')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Purchase Orders</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Purchase Orders</h5>
        <p class="page-subtitle">Orders sent to suppliers</p>
    </div>
    <a href="{{ route('procurement.orders.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New PO
    </a>
</div>

@php $statusColors = ['draft'=>'secondary','sent'=>'info','confirmed'=>'primary','received'=>'success','cancelled'=>'danger']; @endphp

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr><th>PO No.</th><th>Date</th><th>Supplier</th><th>Linked PR</th><th class="text-end">Amount</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($orders as $order)
            <tr>
                <td><a href="{{ route('procurement.orders.show',$order) }}" class="fw-semibold">{{ $order->po_number ?? 'PO-'.$order->id }}</a></td>
                <td>{{ $order->date?->format('d M Y') }}</td>
                <td>{{ $order->supplier?->name ?? '—' }}</td>
                <td>
                    @if($order->purchaseRequest)
                        <a href="{{ route('procurement.requests.show',$order->purchaseRequest) }}">{{ $order->purchaseRequest->pr_number ?? 'PR-'.$order->purchase_request_id }}</a>
                    @else —
                    @endif
                </td>
                <td class="text-end fw-semibold">{{ number_format($order->total_amount,2) }}</td>
                <td><span class="badge bg-{{ $statusColors[$order->status] ?? 'secondary' }}-subtle text-{{ $statusColors[$order->status] ?? 'secondary' }}">{{ ucfirst($order->status) }}</span></td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('procurement.orders.show',$order) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                            @if($order->status === 'draft')
                            <li><a class="dropdown-item" href="{{ route('procurement.orders.edit',$order) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('procurement.orders.destroy',$order) }}" onsubmit="return confirm('Delete this PO?')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                            @endif
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No purchase orders.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($orders->hasPages())
    <div class="card-footer bg-white">{{ $orders->links() }}</div>
    @endif
</div>
@endsection
