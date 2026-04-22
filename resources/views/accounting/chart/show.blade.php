@extends('layouts.app')
@section('title',$chart->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('accounting.chart.index') }}">Chart of Accounts</a></li>
    <li class="breadcrumb-item active">{{ $chart->name }}</li>
@endsection

@section('content')
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $chart->name }}</h5>
        <p class="page-subtitle text-muted">{{ ucfirst($chart->type) }} Account{{ $chart->code ? ' · '.$chart->code : '' }}</p>
    </div>
    <div class="d-flex gap-2">
        <a href="{{ route('accounting.chart.edit',$chart) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit</a>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Account Details</div>
            <div class="card-body">
                <dl class="row mb-0 small">
                    <dt class="col-5 text-muted">Code</dt><dd class="col-7">{{ $chart->code ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Type</dt><dd class="col-7">{{ ucfirst($chart->type) }}</dd>
                    <dt class="col-5 text-muted">Parent</dt><dd class="col-7">{{ $chart->parent?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Status</dt>
                    <dd class="col-7">
                        @if($chart->is_active)<span class="badge bg-success-subtle text-success">Active</span>
                        @else<span class="badge bg-secondary-subtle text-secondary">Inactive</span>@endif
                    </dd>
                    @if($chart->description)
                    <dt class="col-5 text-muted">Description</dt><dd class="col-7">{{ $chart->description }}</dd>
                    @endif
                </dl>
            </div>
        </div>

        @if($chart->children->count())
        <div class="card mt-3">
            <div class="card-header bg-white py-2 fw-semibold">Sub-Accounts</div>
            <div class="list-group list-group-flush">
                @foreach($chart->children as $child)
                <a href="{{ route('accounting.chart.show',$child) }}" class="list-group-item list-group-item-action d-flex justify-content-between">
                    <span>{{ $child->name }}</span>
                    <code class="text-muted small">{{ $child->code }}</code>
                </a>
                @endforeach
            </div>
        </div>
        @endif
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header bg-white py-2 fw-semibold">Recent Journal Lines</div>
            <div class="card-body p-0">
                <table class="table table-sm table-hover mb-0">
                    <thead class="table-light"><tr><th>Date</th><th>Reference</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                    @forelse($chart->journalLines->take(10) as $line)
                    <tr>
                        <td>{{ $line->journalEntry?->date?->format('d M Y') }}</td>
                        <td>{{ $line->journalEntry?->reference_no ?? '—' }}</td>
                        <td class="text-end">{{ $line->debit > 0 ? number_format($line->debit,2) : '—' }}</td>
                        <td class="text-end">{{ $line->credit > 0 ? number_format($line->credit,2) : '—' }}</td>
                    </tr>
                    @empty
                    <tr><td colspan="4" class="text-center text-muted py-3">No journal entries yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection
