@extends('layouts.app')
@section('title','Daily Allowance')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.daily.index') }}">Daily Allowances</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><i class="bi bi-cash-coin me-2"></i>Daily Allowance</span>
        @php $sc = ['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$allowance->status] ?? 'secondary' @endphp
        <span class="badge bg-{{ $sc }} fs-6">{{ ucfirst($allowance->status) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Employee</dt>
            <dd class="col-sm-8">{{ $allowance->employee?->full_name }}</dd>
            <dt class="col-sm-4">Category</dt>
            <dd class="col-sm-8">{{ $allowance->category?->category }}</dd>
            <dt class="col-sm-4">Items</dt>
            <dd class="col-sm-8">{{ $allowance->number_of_items }}</dd>
            <dt class="col-sm-4">Amount/Item</dt>
            <dd class="col-sm-8">{{ number_format($allowance->amount_per_item, 2) }}</dd>
            <dt class="col-sm-4">Total Amount</dt>
            <dd class="col-sm-8"><strong>{{ number_format($allowance->amount, 2) }}</strong></dd>
            <dt class="col-sm-4">Date</dt>
            <dd class="col-sm-8">{{ $allowance->date ?? '—' }}</dd>
            <dt class="col-sm-4">Remark</dt>
            <dd class="col-sm-8">{{ $allowance->remark ?? '—' }}</dd>
            <dt class="col-sm-4">HOD Approved</dt>
            <dd class="col-sm-8">
                @if($allowance->hod_is_approved)
                    <span class="badge bg-success">Yes</span>
                @else
                    <span class="badge bg-secondary">No</span>
                @endif
            </dd>
            <dt class="col-sm-4">HR Approved</dt>
            <dd class="col-sm-8">
                @if($allowance->hr_is_approved)
                    <span class="badge bg-success">Yes</span>
                @else
                    <span class="badge bg-secondary">No</span>
                @endif
            </dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('allowance.daily.edit', $allowance) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('allowance.daily.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
</div>
</div>
@endsection
