@extends('layouts.app')
@section('title','Rating Scales')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Rating Scales</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Appraisal Rating Scales</h5><p class="page-subtitle">Define performance rating levels</p></div>
    <a href="{{ route('appraisals.scales.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> Add Scale</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>Scale</th><th class="text-center">Min %</th><th class="text-center">Max %</th><th>Description</th><th></th></tr></thead>
            <tbody>
            @forelse($scales as $scale)
            <tr>
                <td class="fw-semibold">{{ $scale->scale }}</td>
                <td class="text-center">{{ $scale->minimum_percent }}%</td>
                <td class="text-center">{{ $scale->maximum_percent }}%</td>
                <td class="text-muted">{{ $scale->description ?? '—' }}</td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('appraisals.scales.edit',$scale) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li><form method="POST" action="{{ route('appraisals.scales.destroy',$scale) }}" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button></form></li>
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No rating scales.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
