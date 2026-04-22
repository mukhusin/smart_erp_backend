@extends('layouts.app')
@section('title','Allowance Details')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.requests.index') }}">Emp Allowances</a></li>
    <li class="breadcrumb-item active">Details</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between">
        <span class="fw-semibold">Allowance Details</span>
        @php $sc=['pending'=>'warning','approved'=>'success','rejected'=>'danger'][$allowance->status]??'secondary'@endphp
        <span class="badge bg-{{ $sc }}">{{ ucfirst($allowance->status) }}</span>
    </div>
    <div class="card-body">
        <dl class="row mb-0">
            <dt class="col-sm-4">Employee</dt><dd class="col-sm-8">{{ $allowance->employee?->full_name }}</dd>
            <dt class="col-sm-4">Category</dt><dd class="col-sm-8">{{ $allowance->category }} ({{ $allowance->duration }})</dd>
            <dt class="col-sm-4">Items × Amount</dt><dd class="col-sm-8">{{ $allowance->items }} × {{ number_format($allowance->amount_per_item,2) }}</dd>
            <dt class="col-sm-4">Total Amount</dt><dd class="col-sm-8"><strong>{{ number_format($allowance->amount,2) }}</strong></dd>
            <dt class="col-sm-4">Period</dt><dd class="col-sm-8">{{ $allowance->start_date }} → {{ $allowance->end_date }}</dd>
            <dt class="col-sm-4">HOD</dt><dd class="col-sm-8">{{ $allowance->hodApprover?->full_name ?? '—' }} {{ $allowance->is_hod_approved ? '<span class="badge bg-success ms-1">✓</span>' : '' }}</dd>
            <dt class="col-sm-4">HR</dt><dd class="col-sm-8">{{ $allowance->hrApprover?->full_name ?? '—' }} {{ $allowance->is_hr_approved ? '<span class="badge bg-success ms-1">✓</span>' : '' }}</dd>
        </dl>
    </div>
    <div class="card-footer d-flex gap-2">
        <a href="{{ route('allowance.requests.edit', $allowance) }}" class="btn btn-primary btn-sm">Edit</a>
        <a href="{{ route('allowance.requests.index') }}" class="btn btn-secondary btn-sm">Back</a>
    </div>
</div>
</div></div>
@endsection
