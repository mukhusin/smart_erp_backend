@extends('layouts.app')
@section('title','Purchase Requests')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Purchase Requests</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Purchase Requests</h5>
        <p class="page-subtitle">Departmental requisitions</p>
    </div>
    <a href="{{ route('procurement.requests.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Request
    </a>
</div>

@php $statusColors = ['draft'=>'secondary','pending'=>'warning','approved'=>'success','rejected'=>'danger','ordered'=>'primary']; @endphp

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(array_keys($statusColors) as $st)
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
                <tr><th>PR No.</th><th>Date</th><th>Department</th><th>Requested By</th><th class="text-center">Items</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($requests as $pr)
            <tr>
                <td><a href="{{ route('procurement.requests.show',$pr) }}" class="fw-semibold">{{ $pr->pr_number ?? 'PR-'.$pr->id }}</a></td>
                <td>{{ $pr->date?->format('d M Y') }}</td>
                <td>{{ $pr->department?->name ?? '—' }}</td>
                <td>{{ $pr->requester?->full_name ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-secondary">{{ $pr->items->count() }}</span></td>
                <td><span class="badge bg-{{ $statusColors[$pr->status] ?? 'secondary' }}-subtle text-{{ $statusColors[$pr->status] ?? 'secondary' }}">{{ ucfirst($pr->status) }}</span></td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('procurement.requests.show',$pr) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                            @if($pr->status === 'pending')
                            <li>
                                <form method="POST" action="{{ route('procurement.requests.approve',$pr) }}">@csrf<button class="dropdown-item text-success"><i class="bi bi-check-circle me-2"></i>Approve</button></form>
                            </li>
                            <li>
                                <form method="POST" action="{{ route('procurement.requests.reject',$pr) }}">@csrf<button class="dropdown-item text-danger"><i class="bi bi-x-circle me-2"></i>Reject</button></form>
                            </li>
                            @endif
                            @if($pr->status === 'approved')
                            <li><a class="dropdown-item text-primary" href="{{ route('procurement.orders.create') }}?pr_id={{ $pr->id }}"><i class="bi bi-cart-check me-2"></i>Create PO</a></li>
                            @endif
                            @if(in_array($pr->status,['draft','pending']))
                            <li><a class="dropdown-item" href="{{ route('procurement.requests.edit',$pr) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            @endif
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No purchase requests.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($requests->hasPages())
    <div class="card-footer bg-white">{{ $requests->links() }}</div>
    @endif
</div>
@endsection
