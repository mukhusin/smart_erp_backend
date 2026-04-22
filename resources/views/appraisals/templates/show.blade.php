@extends('layouts.app')
@section('title',$template->appraisal_title)
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('appraisals.templates.index') }}">Templates</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($template->appraisal_title,30) }}</li>
@endsection
@section('content')
<div class="d-flex align-items-start justify-content-between mb-3">
    <div><h5 class="page-title">{{ $template->appraisal_title }}</h5><p class="page-subtitle text-muted">{{ $template->department?->name }} {{ $template->jobPosition ? '· '.$template->jobPosition->title : '' }}</p></div>
    <a href="{{ route('appraisals.templates.edit',$template) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
</div>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Details</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-5 text-muted">Department</dt><dd class="col-7">{{ $template->department?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Position</dt><dd class="col-7">{{ $template->jobPosition?->title ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Status</dt><dd class="col-7">@if($template->is_active)<span class="badge bg-success-subtle text-success">Active</span>@else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif</dd>
                </dl>
                @if($template->description)<hr><p class="small text-muted mb-0">{{ $template->description }}</p>@endif
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header bg-white py-2 d-flex justify-content-between fw-semibold">
                <span>KPIs <span class="badge bg-primary ms-1">{{ $template->kpis->count() }}</span></span>
                @php $totalWeight = $template->kpis->sum('weight') @endphp
                <span class="small text-muted">Total weight: {{ $totalWeight }}%</span>
            </div>
            <div class="card-body p-0">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>#</th><th>KPI Title</th><th>Description</th><th class="text-center">Weight</th><th></th></tr></thead>
                    <tbody>
                    @forelse($template->kpis as $i => $kpi)
                    <tr>
                        <td>{{ $i+1 }}</td>
                        <td class="fw-semibold">{{ $kpi->kpi_title }}</td>
                        <td class="text-muted small">{{ $kpi->description ?? '—' }}</td>
                        <td class="text-center"><span class="badge bg-info-subtle text-info">{{ $kpi->weight }}%</span></td>
                        <td class="text-end">
                            <form method="POST" action="{{ route('appraisals.templates.kpis.destroy',[$template,$kpi]) }}" onsubmit="return confirm('Remove KPI?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                            </form>
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="5" class="text-muted text-center py-3">No KPIs yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="card-footer bg-white">
                <form method="POST" action="{{ route('appraisals.templates.kpis.store',$template) }}" class="row g-2 align-items-end">
                    @csrf
                    <div class="col-md-4"><input type="text" name="kpi_title" class="form-control form-control-sm" placeholder="KPI Title" required></div>
                    <div class="col-md-4"><input type="text" name="description" class="form-control form-control-sm" placeholder="Description"></div>
                    <div class="col-md-2"><input type="number" name="weight" class="form-control form-control-sm" placeholder="Weight %" min="0" max="100" step="0.01" required></div>
                    <div class="col-md-2"><button type="submit" class="btn btn-sm btn-success w-100"><i class="bi bi-plus me-1"></i>Add KPI</button></div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
