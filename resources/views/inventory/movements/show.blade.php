@extends('layouts.app')
@section('title','Movement Detail')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('inventory.movements.index') }}">Movements</a></li>
    <li class="breadcrumb-item active">Detail</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card">
    <div class="card-header bg-white py-3 d-flex align-items-center justify-content-between">
        <span class="fw-semibold"><i class="bi bi-arrow-left-right me-2 text-warning"></i>Stock Movement Detail</span>
        @if($movement->type === 'in')
            <span class="badge bg-success fs-6">Stock In</span>
        @elseif($movement->type === 'out')
            <span class="badge bg-danger fs-6">Stock Out</span>
        @else
            <span class="badge bg-warning text-dark fs-6">Adjustment</span>
        @endif
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Item</dt>
            <dd class="col-sm-8">
                <a href="{{ route('inventory.items.show', $movement->item) }}" class="fw-semibold text-decoration-none">{{ $movement->item?->name }}</a>
                @if($movement->item?->code)<small class="text-muted ms-1">({{ $movement->item->code }})</small>@endif
            </dd>

            <dt class="col-sm-4 text-muted">Category</dt>
            <dd class="col-sm-8">{{ $movement->item?->category?->name ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Date</dt>
            <dd class="col-sm-8">{{ $movement->date?->format('d M Y') }}</dd>

            <dt class="col-sm-4 text-muted">Type</dt>
            <dd class="col-sm-8">
                @if($movement->type === 'in')<span class="badge bg-success-subtle text-success">Stock In</span>
                @elseif($movement->type === 'out')<span class="badge bg-danger-subtle text-danger">Stock Out</span>
                @else<span class="badge bg-warning-subtle text-warning">Adjustment</span>@endif
            </dd>

            <dt class="col-sm-4 text-muted">Quantity</dt>
            <dd class="col-sm-8 fw-semibold fs-5 {{ $movement->type === 'out' ? 'text-danger' : ($movement->type === 'in' ? 'text-success' : '') }}">
                {{ $movement->type === 'out' ? '−' : ($movement->type === 'in' ? '+' : '≡') }}{{ number_format($movement->quantity, 2) }}
                {{ $movement->item?->unit }}
            </dd>

            <dt class="col-sm-4 text-muted">Reference</dt>
            <dd class="col-sm-8">{{ $movement->reference ?? '—' }}</dd>

            <dt class="col-sm-4 text-muted">Notes</dt>
            <dd class="col-sm-8">{{ $movement->notes ?? '—' }}</dd>

            @if($movement->creator)
            <dt class="col-sm-4 text-muted">Recorded By</dt>
            <dd class="col-sm-8">{{ $movement->creator->name }}</dd>
            @endif

            <dt class="col-sm-4 text-muted">Recorded At</dt>
            <dd class="col-sm-8">{{ $movement->created_at?->format('d M Y H:i') }}</dd>
        </dl>
    </div>
    <div class="card-footer bg-white">
        <a href="{{ route('inventory.movements.index') }}" class="btn btn-light"><i class="bi bi-arrow-left me-1"></i>Back</a>
        <a href="{{ route('inventory.items.show', $movement->item) }}" class="btn btn-outline-primary ms-2"><i class="bi bi-box-seam me-1"></i>View Item</a>
    </div>
</div>
</div></div>
@endsection
