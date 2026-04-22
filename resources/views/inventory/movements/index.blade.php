@extends('layouts.app')
@section('title','Stock Movements')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Stock Movements</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Stock Movements</h5><p class="page-subtitle">All stock in / out / adjustment records</p></div>
    <a href="{{ route('inventory.movements.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Record Movement</a>
</div>
@if(session('success'))<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>@endif
<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Item</label>
                <select name="item_id" class="form-select form-select-sm">
                    <option value="">All Items</option>
                    @foreach($items as $it)<option value="{{ $it->id }}" {{ request('item_id')==$it->id?'selected':'' }}>{{ $it->name }}</option>@endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Type</label>
                <select name="type" class="form-select form-select-sm">
                    <option value="">All Types</option>
                    <option value="in" {{ request('type')=='in'?'selected':'' }}>Stock In</option>
                    <option value="out" {{ request('type')=='out'?'selected':'' }}>Stock Out</option>
                    <option value="adjustment" {{ request('type')=='adjustment'?'selected':'' }}>Adjustment</option>
                </select>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button></div>
        </form>
    </div>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>Date</th><th>Item</th><th>Category</th><th>Type</th><th class="text-end">Quantity</th><th>Reference</th><th>Notes</th><th></th></tr></thead>
            <tbody>
            @forelse($movements as $mv)
            <tr>
                <td>{{ $mv->date?->format('d M Y') }}</td>
                <td>
                    <a href="{{ route('inventory.items.show', $mv->item) }}" class="text-decoration-none fw-semibold">{{ $mv->item?->name }}</a>
                </td>
                <td class="text-muted small">{{ $mv->item?->category?->name ?? '—' }}</td>
                <td>
                    @if($mv->type === 'in')<span class="badge bg-success-subtle text-success">Stock In</span>
                    @elseif($mv->type === 'out')<span class="badge bg-danger-subtle text-danger">Stock Out</span>
                    @else<span class="badge bg-warning-subtle text-warning">Adjustment</span>@endif
                </td>
                <td class="text-end {{ $mv->type === 'out' ? 'text-danger' : ($mv->type === 'in' ? 'text-success' : '') }} fw-semibold">
                    {{ $mv->type === 'out' ? '-' : ($mv->type === 'in' ? '+' : '') }}{{ number_format($mv->quantity, 2) }}
                </td>
                <td>{{ $mv->reference ?? '—' }}</td>
                <td>{{ Str::limit($mv->notes, 30) ?? '—' }}</td>
                <td><a href="{{ route('inventory.movements.show', $mv) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-eye"></i></a></td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No movements recorded.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($movements->hasPages())<div class="card-footer bg-white">{{ $movements->links() }}</div>@endif
</div>
@endsection
