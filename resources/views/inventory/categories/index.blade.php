@extends('layouts.app')
@section('title','Inventory Categories')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Inventory Categories</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Inventory Categories</h5><p class="page-subtitle">Organise items by category</p></div>
    <a href="{{ route('inventory.categories.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Category</a>
</div>
@if(session('success'))<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i>{{ session('success') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>@endif
@if(session('error'))<div class="alert alert-danger alert-dismissible fade show"><i class="bi bi-exclamation-circle me-2"></i>{{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>@endif
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>Name</th><th>Description</th><th class="text-center">Items</th><th></th></tr></thead>
            <tbody>
            @forelse($categories as $cat)
            <tr>
                <td class="fw-semibold">{{ $cat->name }}</td>
                <td class="text-muted">{{ Str::limit($cat->description, 60) ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-primary-subtle text-primary">{{ $cat->items_count }}</span></td>
                <td class="text-end">
                    <a href="{{ route('inventory.categories.edit', $cat) }}" class="btn btn-sm btn-outline-secondary me-1"><i class="bi bi-pencil"></i></a>
                    <form method="POST" action="{{ route('inventory.categories.destroy', $cat) }}" class="d-inline" onsubmit="return confirm('Delete this category?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                </td>
            </tr>
            @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No categories yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
