@extends('layouts.app')
@section('title','Allowance Categories')
@section('breadcrumb')<li class="breadcrumb-item active">Allowance Categories</li>@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <h5 class="page-title mb-0">Allowance Categories</h5>
    <a href="{{ route('allowance.categories.create') }}" class="btn btn-primary btn-sm"><i class="bi bi-plus-lg me-1"></i> New</a>
</div>
<div class="card shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover mb-0">
            <thead class="table-light"><tr><th>Category</th><th>Code</th><th>Duration</th><th>Claimed</th><th>Deduct Leave</th><th>Status</th><th></th></tr></thead>
            <tbody>
                @forelse($categories as $cat)
                <tr>
                    <td>{{ $cat->category }}</td>
                    <td><span class="badge bg-secondary">{{ $cat->code }}</span></td>
                    <td class="text-capitalize">{{ $cat->duration }}</td>
                    <td>{{ $cat->claimed ? 'Yes' : 'No' }}</td>
                    <td>{{ $cat->deduct_leave ? 'Yes' : 'No' }}</td>
                    <td><span class="badge {{ $cat->is_active ? 'bg-success' : 'bg-secondary' }}">{{ $cat->is_active ? 'Active' : 'Inactive' }}</span></td>
                    <td class="text-end">
                        <a href="{{ route('allowance.categories.edit', $cat) }}" class="btn btn-sm btn-outline-primary"><i class="bi bi-pencil"></i></a>
                        <form method="POST" action="{{ route('allowance.categories.destroy', $cat) }}" class="d-inline" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button></form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No categories.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if($categories->hasPages())<div class="card-footer">{{ $categories->links() }}</div>@endif
</div>
@endsection
