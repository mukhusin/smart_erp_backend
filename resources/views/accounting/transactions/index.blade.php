@extends('layouts.app')
@section('title','Transactions')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Transactions</li>
@endsection

@section('content')
<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="page-title">Transactions</h5>
        <p class="page-subtitle">Posted journal entries ledger</p>
    </div>
    <a href="{{ route('accounting.journals.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i> New Journal Entry
    </a>
</div>

<div class="card mb-3">
    <div class="card-body py-2">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">From Date</label>
                <input type="date" name="from" class="form-control form-control-sm" value="{{ request('from') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">To Date</label>
                <input type="date" name="to" class="form-control form-control-sm" value="{{ request('to') }}">
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Account</label>
                <select name="account_id" class="form-select form-select-sm">
                    <option value="">All Accounts</option>
                    @foreach($accounts as $acc)
                    <option value="{{ $acc->id }}" {{ request('account_id')==$acc->id?'selected':'' }}>{{ $acc->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-outline-secondary btn-sm w-100"><i class="bi bi-funnel me-1"></i>Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <table class="table table-hover table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>Date</th>
                    <th>Reference</th>
                    <th>Description</th>
                    <th class="text-end">Total Debit</th>
                    <th class="text-end">Total Credit</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            @forelse($transactions as $t)
            <tr>
                <td>{{ $t->date?->format('d M Y') }}</td>
                <td><code>{{ $t->reference_no ?? '—' }}</code></td>
                <td>{{ Str::limit($t->description,60) ?? '—' }}</td>
                <td class="text-end">{{ number_format($t->total_debit,2) }}</td>
                <td class="text-end">{{ number_format($t->total_credit,2) }}</td>
                <td class="text-end"><a href="{{ route('accounting.journals.show',$t) }}" class="btn btn-sm btn-light"><i class="bi bi-eye"></i></a></td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No posted transactions found.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if($transactions->hasPages())
    <div class="card-footer bg-white">{{ $transactions->links() }}</div>
    @endif
</div>
@endsection
