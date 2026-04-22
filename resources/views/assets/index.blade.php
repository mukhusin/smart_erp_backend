@extends('layouts.app')
@section('title','Assets')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Assets</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Asset Register</h5>
        <p class="page-subtitle">Track and manage company assets</p>
    </div>
    <a href="{{ route('assets.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Asset</a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Category</label>
                <select name="category_id" class="form-select form-select-sm">
                    <option value="">All Categories</option>
                    @foreach($categories as $c)
                    <option value="{{ $c->id }}" {{ request('category_id')==$c->id?'selected':'' }}>{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    @foreach(['available','in-use','maintenance','disposed'] as $st)
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

@php $statusColors = ['available'=>'success','in-use'=>'primary','maintenance'=>'warning','disposed'=>'secondary']; @endphp

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr><th>Asset Name</th><th>Category</th><th>Location</th><th>Department</th><th>Assigned To</th><th class="text-end">Cost</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            @forelse($assets as $asset)
            <tr>
                <td class="fw-semibold">{{ $asset->name }}</td>
                <td>{{ $asset->category?->name ?? '—' }}</td>
                <td>{{ $asset->location?->name ?? '—' }}</td>
                <td>{{ $asset->department?->name ?? '—' }}</td>
                <td>{{ $asset->employee?->full_name ?? '—' }}</td>
                <td class="text-end">{{ $asset->cost ? number_format($asset->cost,2) : '—' }}</td>
                <td><span class="badge bg-{{ $statusColors[$asset->status] ?? 'secondary' }}-subtle text-{{ $statusColors[$asset->status] ?? 'secondary' }}">{{ ucfirst($asset->status) }}</span></td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('assets.show',$asset) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                            <li><a class="dropdown-item" href="{{ route('assets.edit',$asset) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('assets.destroy',$asset) }}" onsubmit="return confirm('Delete this asset?')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="8" class="text-center text-muted py-4">No assets found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($assets->hasPages())
    <div class="card-footer bg-white">{{ $assets->links() }}</div>
    @endif
</div>
@endsection
