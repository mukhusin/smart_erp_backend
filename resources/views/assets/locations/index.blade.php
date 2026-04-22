@extends('layouts.app')
@section('title','Asset Locations')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('assets.index') }}">Assets</a></li>
    <li class="breadcrumb-item active">Locations</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title">Asset Locations</h5>
    <a href="{{ route('assets.locations.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Location</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>#</th><th>Name</th><th>Description</th><th class="text-center">Assets</th><th></th></tr></thead>
            <tbody>
            @forelse($locations as $i => $loc)
            <tr>
                <td>{{ $i+1 }}</td>
                <td class="fw-semibold">{{ $loc->name }}</td>
                <td class="text-muted">{{ $loc->description ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-primary">{{ $loc->assets_count }}</span></td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('assets.locations.edit',$loc) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li>
                                <form method="POST" action="{{ route('assets.locations.destroy',$loc) }}" onsubmit="return confirm('Delete?')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No locations.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
