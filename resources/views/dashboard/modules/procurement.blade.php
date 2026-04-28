@extends('layouts.app')
@section('title','Procurement Dashboard')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Home</a></li>
    <li class="breadcrumb-item active">Procurement</li>
@endsection
@section('content')
<div class="d-flex align-items-center justify-content-between mb-4">
    <h4 class="page-title mb-0">Procurement Overview</h4>
</div>
<div class="row g-3 mb-4">
    @foreach($stats as $key => $value)
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm text-center py-3" style="border-top:3px solid #0d6efd !important">
            <div class="fw-bold fs-3 text-primary">{!! $value !!}</div>
            <div class="text-muted small">{{ ucwords(str_replace('_',' ',$key)) }}</div>
        </div>
    </div>
    @endforeach
</div>
@endsection
