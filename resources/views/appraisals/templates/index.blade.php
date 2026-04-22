@extends('layouts.app')
@section('title','Appraisal Templates')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Appraisal Templates</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div><h5 class="page-title">Appraisal Templates</h5><p class="page-subtitle">Define KPI frameworks per department/position</p></div>
    <a href="{{ route('appraisals.templates.create') }}" class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i> New Template</a>
</div>
<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light"><tr><th>Title</th><th>Department</th><th>Position</th><th class="text-center">KPIs</th><th>Status</th><th></th></tr></thead>
            <tbody>
            @forelse($appraisals as $appraisal)
            <tr>
                <td class="fw-semibold"><a href="{{ route('appraisals.templates.show',$appraisal) }}">{{ $appraisal->appraisal_title }}</a></td>
                <td>{{ $appraisal->department?->name ?? '—' }}</td>
                <td>{{ $appraisal->jobPosition?->title ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-primary">{{ $appraisal->kpis_count }}</span></td>
                <td>
                    @if($appraisal->is_active)<span class="badge bg-success-subtle text-success">Active</span>
                    @else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
                </td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('appraisals.templates.show',$appraisal) }}"><i class="bi bi-eye me-2"></i>View/KPIs</a></li>
                            <li><a class="dropdown-item" href="{{ route('appraisals.templates.edit',$appraisal) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li><form method="POST" action="{{ route('appraisals.templates.destroy',$appraisal) }}" onsubmit="return confirm('Delete?')">@csrf @method('DELETE')<button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button></form></li>
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No appraisal templates.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
