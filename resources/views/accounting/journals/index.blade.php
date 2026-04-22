@extends('layouts.app')
@section('title','Journal Entries')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Journal Entries</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Journal Entries</h5>
        <p class="page-subtitle">Double-entry bookkeeping records</p>
    </div>
    <a href="{{ route('accounting.journals.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Journal Entry
    </a>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Description</th>
                    <th class="text-center">Lines</th>
                    <th class="text-end">Total Debit</th>
                    <th>Status</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($journals as $journal)
            <tr>
                <td>{{ $journal->date?->format('d M Y') }}</td>
                <td><code>{{ $journal->reference_no ?? '—' }}</code></td>
                <td>{{ Str::limit($journal->description,50) ?? '—' }}</td>
                <td class="text-center"><span class="badge bg-secondary">{{ $journal->lines->count() }}</span></td>
                <td class="text-end">{{ number_format($journal->total_debit,2) }}</td>
                <td>
                    @php $sc = ['draft'=>'secondary','posted'=>'success','reversed'=>'danger'] @endphp
                    <span class="badge bg-{{ $sc[$journal->status] ?? 'secondary' }}-subtle text-{{ $sc[$journal->status] ?? 'secondary' }}">
                        {{ ucfirst($journal->status) }}
                    </span>
                </td>
                <td class="text-end">
                    <div class="dropdown">
                        <button class="btn btn-sm btn-light" data-bs-toggle="dropdown"><i class="bi bi-three-dots"></i></button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('accounting.journals.show',$journal) }}"><i class="bi bi-eye me-2"></i>View</a></li>
                            @if($journal->status === 'draft')
                            <li><a class="dropdown-item" href="{{ route('accounting.journals.edit',$journal) }}"><i class="bi bi-pencil me-2"></i>Edit</a></li>
                            <li>
                                <form method="POST" action="{{ route('accounting.journals.post',$journal) }}">
                                    @csrf
                                    <button class="dropdown-item text-success"><i class="bi bi-check2-circle me-2"></i>Post</button>
                                </form>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="POST" action="{{ route('accounting.journals.destroy',$journal) }}" onsubmit="return confirm('Delete this journal?')">
                                    @csrf @method('DELETE')
                                    <button class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i>Delete</button>
                                </form>
                            </li>
                            @endif
                        </ul>
                    </div>
                </td>
            </tr>
            @empty
            <tr><td colspan="7" class="text-center text-muted py-4">No journal entries yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($journals->hasPages())
    <div class="card-footer bg-white">{{ $journals->links() }}</div>
    @endif
</div>
@endsection
