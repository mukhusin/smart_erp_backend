@extends('layouts.app')
@section('title','Employee Appraisal')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('appraisals.employee.index') }}">Employee Appraisals</a></li>
    <li class="breadcrumb-item active">{{ $employeeAppraisal->employee->full_name }}</li>
@endsection
@section('content')

@php
    $status    = $employeeAppraisal->status;
    $isDraft   = $status === 'draft';
    $isSelf    = $status === 'self_assessed';
    $isHod     = $status === 'hod_reviewed';
    $isDone    = $status === 'completed';
    $badgeMap  = ['draft'=>'secondary','self_assessed'=>'info','hod_reviewed'=>'warning','completed'=>'success'];
    $badge     = $badgeMap[$status] ?? 'secondary';
@endphp

{{-- Header --}}
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $employeeAppraisal->employee->full_name }}</h5>
        <p class="page-subtitle mb-1">
            {{ $employeeAppraisal->departmentAppraisal->appraisal_title }} &mdash; Period: <strong>{{ $employeeAppraisal->period }}</strong>
        </p>
        <span class="badge bg-{{ $badge }}-subtle text-{{ $badge }}">{{ ucfirst(str_replace('_',' ',$status)) }}</span>
    </div>
    <div class="d-flex gap-2">
        @if($isHod)
        <form method="POST" action="{{ route('appraisals.employee.complete', $employeeAppraisal) }}" onsubmit="return confirm('Mark this appraisal as completed?')">
            @csrf
            <button class="btn btn-success"><i class="bi bi-check2-circle me-1"></i> Mark Completed</button>
        </form>
        @endif
        <a href="{{ route('appraisals.employee.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i> Back</a>
    </div>
</div>

{{-- Score summary card (if HOD reviewed or completed) --}}
@if($isHod || $isDone)
<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card text-center border-0 bg-success-subtle">
            <div class="card-body py-3">
                <div class="fs-2 fw-bold text-success">{{ number_format($employeeAppraisal->total_score, 1) }}%</div>
                <div class="small text-muted">Total Score</div>
            </div>
        </div>
    </div>
    @if($employeeAppraisal->reviewer)
    <div class="col-md-5">
        <div class="card border-0 bg-light">
            <div class="card-body py-3">
                <div class="small text-muted">Reviewed by</div>
                <div class="fw-semibold">{{ $employeeAppraisal->reviewer->full_name }}</div>
                <div class="small text-muted">{{ $employeeAppraisal->reviewed_at?->format('d M Y, H:i') }}</div>
            </div>
        </div>
    </div>
    @endif
</div>
@endif

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show" role="alert">
    {{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

@if($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

{{-- ── Self-Assessment Form ────────────────────────────────────────── --}}
@if($isDraft || $isSelf)
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-person-fill-check text-primary"></i> Self-Assessment
        <span class="badge bg-primary-subtle text-primary ms-auto">Employee fills this section</span>
    </div>
    <div class="card-body p-0">
        <form method="POST" action="{{ route('appraisals.employee.self-assessment', $employeeAppraisal) }}">
            @csrf
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:35%">KPI</th>
                        <th class="text-center" style="width:10%">Weight</th>
                        <th style="width:20%">Self Score (0–100)</th>
                        <th>Comment</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($employeeAppraisal->scores as $i => $score)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $score->kpi->kpi_name }}</div>
                        @if($score->kpi->description)
                            <div class="small text-muted">{{ $score->kpi->description }}</div>
                        @endif
                        <input type="hidden" name="scores[{{ $i }}][id]" value="{{ $score->id }}">
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary-subtle text-secondary">{{ $score->kpi->weight }}%</span>
                    </td>
                    <td>
                        <input type="number" name="scores[{{ $i }}][self_score]"
                               class="form-control form-control-sm"
                               min="0" max="100" step="0.5"
                               value="{{ old("scores.{$i}.self_score", $score->self_score) }}">
                    </td>
                    <td>
                        <input type="text" name="scores[{{ $i }}][self_comment]"
                               class="form-control form-control-sm"
                               maxlength="500"
                               value="{{ old("scores.{$i}.self_comment", $score->self_comment) }}">
                    </td>
                </tr>
                @endforeach
                </tbody>
            </table>
            <div class="p-3 border-top">
                <label class="form-label fw-semibold">Overall Employee Comment</label>
                <textarea name="employee_comment" class="form-control" rows="3" maxlength="2000">{{ old('employee_comment', $employeeAppraisal->employee_comment) }}</textarea>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i> Save Self-Assessment</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endif

{{-- ── HOD Agreed Scores Form ──────────────────────────────────────── --}}
@if($isSelf || $isHod || $isDone)
<div class="card mb-4">
    <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <i class="bi bi-clipboard2-check text-warning"></i> HOD Agreed Scores
        @if($isSelf)
            <span class="badge bg-warning-subtle text-warning ms-auto">Awaiting HOD review</span>
        @endif
    </div>
    <div class="card-body p-0">
        <form method="POST" action="{{ route('appraisals.employee.agreed-scores', $employeeAppraisal) }}">
            @csrf
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th style="width:30%">KPI</th>
                        <th class="text-center" style="width:8%">Weight</th>
                        <th class="text-end" style="width:10%">Self Score</th>
                        <th style="width:15%">Agreed Score (0–100)</th>
                        <th>Comment</th>
                        @if($isHod || $isDone)
                            <th class="text-end">Weighted</th>
                        @endif
                    </tr>
                </thead>
                <tbody>
                @foreach($employeeAppraisal->scores as $i => $score)
                <tr>
                    <td>
                        <div class="fw-semibold">{{ $score->kpi->kpi_name }}</div>
                        <input type="hidden" name="scores[{{ $i }}][id]" value="{{ $score->id }}">
                    </td>
                    <td class="text-center">
                        <span class="badge bg-secondary-subtle text-secondary">{{ $score->kpi->weight }}%</span>
                    </td>
                    <td class="text-end">
                        @if($score->self_score !== null)
                            <span class="fw-semibold">{{ $score->self_score }}</span>
                        @else
                            <span class="text-muted">—</span>
                        @endif
                    </td>
                    <td>
                        @if($isDone)
                            <span class="fw-semibold">{{ $score->agreed_score ?? '—' }}</span>
                        @else
                            <input type="number" name="scores[{{ $i }}][agreed_score]"
                                   class="form-control form-control-sm"
                                   min="0" max="100" step="0.5"
                                   value="{{ old("scores.{$i}.agreed_score", $score->agreed_score) }}">
                        @endif
                    </td>
                    <td>
                        @if($isDone)
                            <span class="text-muted">{{ $score->agreed_comment ?? '—' }}</span>
                        @else
                            <input type="text" name="scores[{{ $i }}][agreed_comment]"
                                   class="form-control form-control-sm"
                                   maxlength="500"
                                   value="{{ old("scores.{$i}.agreed_comment", $score->agreed_comment) }}">
                        @endif
                    </td>
                    @if($isHod || $isDone)
                    <td class="text-end">
                        @php
                            $weighted = ($score->agreed_score ?? 0) * (($score->kpi->weight ?? 0) / 100);
                        @endphp
                        <span class="text-muted small">{{ number_format($weighted, 2) }}</span>
                    </td>
                    @endif
                </tr>
                @endforeach
                </tbody>
                @if($isHod || $isDone)
                <tfoot>
                    <tr class="table-light fw-semibold">
                        <td colspan="{{ ($isHod || $isDone) ? 5 : 4 }}">Total Score</td>
                        <td class="text-end text-success">{{ number_format($employeeAppraisal->total_score, 2) }}%</td>
                    </tr>
                </tfoot>
                @endif
            </table>
            @if(!$isDone)
            <div class="p-3 border-top">
                <label class="form-label fw-semibold">HOD Comment</label>
                <textarea name="hod_comment" class="form-control" rows="3" maxlength="2000">{{ old('hod_comment', $employeeAppraisal->hod_comment) }}</textarea>

                {{-- Rating scale reference --}}
                @if($ratingScales->count())
                <div class="mt-3">
                    <p class="small fw-semibold text-muted mb-1">Rating Scale Reference</p>
                    <div class="d-flex flex-wrap gap-2">
                        @foreach($ratingScales as $rs)
                        <span class="badge bg-light text-dark border">
                            {{ $rs->scale_label }}: {{ $rs->min_score }}–{{ $rs->max_score }}
                        </span>
                        @endforeach
                    </div>
                </div>
                @endif

                <div class="mt-3">
                    <button type="submit" class="btn btn-warning text-dark"><i class="bi bi-floppy me-1"></i> Save Agreed Scores</button>
                </div>
            </div>
            @else
            {{-- Read-only HOD comment when completed --}}
            @if($employeeAppraisal->hod_comment)
            <div class="p-3 border-top">
                <p class="small fw-semibold text-muted mb-1">HOD Comment</p>
                <p class="mb-0">{{ $employeeAppraisal->hod_comment }}</p>
            </div>
            @endif
            @endif
        </form>
    </div>
</div>
@endif

{{-- Employee self-assessment read-only view (after HOD review) --}}
@if(($isHod || $isDone) && $employeeAppraisal->employee_comment)
<div class="card mb-3">
    <div class="card-header fw-semibold"><i class="bi bi-chat-left-text me-1"></i> Employee Comment</div>
    <div class="card-body">{{ $employeeAppraisal->employee_comment }}</div>
</div>
@endif

@endsection
