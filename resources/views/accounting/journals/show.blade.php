@extends('layouts.app')
@section('title','Journal Entry')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item"><a href="{{ route('accounting.journals.index') }}">Journal Entries</a></li>
    <li class="breadcrumb-item active">{{ $journal->reference_no ?? 'Entry #'.$journal->id }}</li>
@endsection

@section('content')
<div class="d-flex align-items-start justify-content-between mb-3">
    <div>
        <h5 class="page-title">{{ $journal->reference_no ?? 'Journal Entry #'.$journal->id }}</h5>
        <p class="page-subtitle text-muted">{{ $journal->date?->format('d M Y') }}</p>
    </div>
    <div class="d-flex gap-2">
        @if($journal->status === 'draft')
        <a href="{{ route('accounting.journals.edit',$journal) }}" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil me-1"></i>Edit</a>
        <form method="POST" action="{{ route('accounting.journals.post',$journal) }}">
            @csrf
            <button class="btn btn-sm btn-success"><i class="bi bi-check2-circle me-1"></i>Post</button>
        </form>
        @endif
    </div>
</div>

<div class="card">
    <div class="card-header bg-white py-2 d-flex align-items-center justify-content-between">
        <span class="fw-semibold">{{ $journal->description ?? 'No description' }}</span>
        @php $sc = ['draft'=>'secondary','posted'=>'success','reversed'=>'danger'] @endphp
        <span class="badge bg-{{ $sc[$journal->status] ?? 'secondary' }}">{{ ucfirst($journal->status) }}</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Account</th>
                    <th>Description</th>
                    <th class="text-end">Debit</th>
                    <th class="text-end">Credit</th>
                </tr>
            </thead>
            <tbody>
            @foreach($journal->lines as $line)
            <tr>
                <td>
                    <span class="fw-semibold">{{ $line->account?->name }}</span>
                    @if($line->account?->code)<code class="text-muted ms-1 small">{{ $line->account->code }}</code>@endif
                </td>
                <td class="text-muted">{{ $line->description ?? '—' }}</td>
                <td class="text-end">{{ $line->debit > 0 ? number_format($line->debit,2) : '' }}</td>
                <td class="text-end">{{ $line->credit > 0 ? number_format($line->credit,2) : '' }}</td>
            </tr>
            @endforeach
            </tbody>
            <tfoot class="table-light fw-semibold">
                <tr>
                    <td colspan="2" class="text-end">Totals</td>
                    <td class="text-end">{{ number_format($journal->total_debit,2) }}</td>
                    <td class="text-end">{{ number_format($journal->total_credit,2) }}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    <div class="card-footer bg-white small text-muted">
        Created by {{ $journal->creator?->name ?? 'System' }} &middot; {{ $journal->created_at?->format('d M Y H:i') }}
    </div>
</div>
@endsection
