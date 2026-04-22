@extends('layouts.app')
@section('title','Item Detail')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('inventory.items.index') }}">Items</a></li>
    <li class="breadcrumb-item active">{{ $item->name }}</li>
@endsection
@section('content')
@if(session('success'))<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>@endif
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title mb-0">{{ $item->name }}</h5>
        @if($item->code)<small class="text-muted">{{ $item->code }}</small>@endif
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('inventory.movements.create', ['item_id' => $item->id]) }}" class="btn btn-warning"><i class="bi bi-arrow-left-right me-1"></i>Record Movement</a>
        <a href="{{ route('inventory.items.edit', $item) }}" class="btn btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center h-100">
            <div class="card-body py-4">
                <div class="fs-1 fw-bold {{ $item->isLowStock() ? 'text-danger' : 'text-primary' }}">{{ number_format($item->quantity_on_hand, 2) }}</div>
                <div class="text-muted small">On Hand {{ $item->unit ? '('.$item->unit.')' : '' }}</div>
                @if($item->isLowStock())<span class="badge bg-danger mt-1">Low Stock</span>@endif
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Min Stock Level</div>
                <div class="fw-semibold">{{ number_format($item->minimum_stock, 2) }} {{ $item->unit }}</div>
                <div class="text-muted small mt-3 mb-1">Unit Cost</div>
                <div class="fw-semibold">{{ number_format($item->unit_cost, 2) }}</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Category</div>
                <div class="fw-semibold">{{ $item->category?->name ?? '—' }}</div>
                <div class="text-muted small mt-3 mb-1">Status</div>
                @if($item->is_active)<span class="badge bg-success-subtle text-success">Active</span>@else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small mb-1">Stock Value</div>
                <div class="fw-semibold fs-5">{{ number_format($item->quantity_on_hand * $item->unit_cost, 2) }}</div>
                <div class="text-muted small mt-3 mb-1">Description</div>
                <div class="small">{{ $item->description ?? '—' }}</div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header bg-white fw-semibold py-3"><i class="bi bi-arrow-left-right me-2 text-warning"></i>Stock Movement History</div>
    <div class="card-body p-0">
        <table class="table table-sm table-hover mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Type</th><th class="text-end">Quantity</th><th>Reference</th><th>Notes</th><th></th></tr></thead>
            <tbody>
            @forelse($item->movements->sortByDesc('date') as $mv)
            <tr>
                <td>{{ $mv->date?->format('d M Y') }}</td>
                <td>
                    @if($mv->type === 'in')<span class="badge bg-success-subtle text-success">Stock In</span>
                    @elseif($mv->type === 'out')<span class="badge bg-danger-subtle text-danger">Stock Out</span>
                    @else<span class="badge bg-warning-subtle text-warning">Adjustment</span>@endif
                </td>
                <td class="text-end {{ $mv->type === 'out' ? 'text-danger' : ($mv->type === 'in' ? 'text-success' : '') }}">
                    {{ $mv->type === 'out' ? '-' : ($mv->type === 'in' ? '+' : '') }}{{ number_format($mv->quantity, 2) }}
                </td>
                <td>{{ $mv->reference ?? '—' }}</td>
                <td>{{ Str::limit($mv->notes, 40) ?? '—' }}</td>
                <td><a href="{{ route('inventory.movements.show', $mv) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-3">No movements recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
