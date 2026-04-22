@extends('layouts.app')
@section('title','Inventory Items')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Inventory Items</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Inventory Items</h5><p class="page-subtitle">Manage stock items and quantities</p></div>
    <a href="{{ route('inventory.items.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Item</a>
</div>
@if(session('success'))<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>@endif
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Category</label>
                <select name="category_id" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)<option value="{{ $cat->id }}" {{ request('category_id')==$cat->id?'selected':'' }}>{{ $cat->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3">
                <div class="form-check mt-3">
                    <input class="form-check-input" type="checkbox" name="low_stock" id="lowStock" value="1" {{ request('low_stock')?'checked':'' }}>
                    <label class="form-check-label small" for="lowStock">Low Stock Only</label>
                </div>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>Code</th><th>Name</th><th>Category</th><th>Unit</th><th class="text-end">On Hand</th><th class="text-end">Min Stock</th><th class="text-end">Unit Cost</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($items as $item)
            <tr>
                <td class="text-muted small">{{ $item->code ?? '—' }}</td>
                <td><a href="{{ route('inventory.items.show', $item) }}" class="fw-semibold text-decoration-none">{{ $item->name }}</a></td>
                <td>{{ $item->category?->name ?? '—' }}</td>
                <td>{{ $item->unit ?? '—' }}</td>
                <td class="text-end {{ $item->isLowStock() ? 'text-danger fw-semibold' : '' }}">{{ number_format($item->quantity_on_hand, 2) }}</td>
                <td class="text-end text-muted">{{ number_format($item->minimum_stock, 2) }}</td>
                <td class="text-end">{{ number_format($item->unit_cost, 2) }}</td>
                <td>
                    @if($item->isLowStock())
                        <span class="badge bg-danger-subtle text-danger">Low Stock</span>
                    @elseif($item->is_active)
                        <span class="badge bg-success-subtle text-success">Active</span>
                    @else
                        <span class="badge bg-secondary-subtle text-secondary">Inactive</span>
                    @endif
                </td>
                <td class="text-end">
                    <a href="{{ route('inventory.movements.create', ['item_id' => $item->id]) }}" class="btn btn-sm btn-outline-warning me-1" title="Record Movement"><i class="bi bi-arrow-left-right"></i></a>
                    <a href="{{ route('inventory.items.edit', $item) }}" class="btn btn-sm btn-outline-secondary me-1"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="{{ route('inventory.items.destroy', $item) }}" class="d-inline" onsubmit="return confirm('Delete this item?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </td>
            </tr>
            @empty
            <tr><td colspan="9" class="text-center text-muted py-4">No items found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($items->hasPages())<div class="card-footer bg-white">{{ $items->links() }}</div>@endif
</div>
@endsection
