@extends('layouts.app')
@section('title',$asset->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('assets.index') }}">Assets</a></li>
    <li class="breadcrumb-item active">{{ $asset->name }}</li>
@endsection

@section('content')
@php $statusColors = ['available'=>'success','in-use'=>'primary','maintenance'=>'warning','disposed'=>'secondary']; @endphp
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $asset->name }}</h5>
        <p class="page-subtitle text-muted">{{ $asset->category?->name }} {{ $asset->manufacturer ? '· '.$asset->manufacturer : '' }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('assets.edit',$asset) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Asset Details</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7"><span class="badge bg-{{ $statusColors[$asset->status]??'secondary' }}">{{ ucfirst($asset->status) }}</span></dd>
                    <dt class="col-5 text-muted">Category</dt><dd class="col-7">{{ $asset->category?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Location</dt><dd class="col-7">{{ $asset->location?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Department</dt><dd class="col-7">{{ $asset->department?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Assigned To</dt><dd class="col-7">{{ $asset->employee?->full_name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Model</dt><dd class="col-7">{{ $asset->model ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Manufacturer</dt><dd class="col-7">{{ $asset->manufacturer ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Serial No.</dt><dd class="col-7"><code>{{ $asset->serial_number ?? '—' }}</code></dd>
                    <dt class="col-5 text-muted">Barcode</dt><dd class="col-7"><code>{{ $asset->barcode ?? '—' }}</code></dd>
                    <dt class="col-5 text-muted">Cost</dt><dd class="col-7">{{ $asset->cost ? number_format($asset->cost,2) : '—' }}</dd>
                    <dt class="col-5 text-muted">Purchase Date</dt><dd class="col-7">{{ $asset->purchase_date?->format('d M Y') ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Warranty</dt><dd class="col-7">{{ $asset->warranty_expiration_date?->format('d M Y') ?? '—' }}</dd>
                </dl>
                @if($asset->description)<hr><p class="small text-muted mb-0">{{ $asset->description }}</p>@endif
            </div>
        </div>
    </div>
</div>
@endsection
