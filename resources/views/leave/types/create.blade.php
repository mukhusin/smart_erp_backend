@extends('layouts.app')
@section('title','New Leave Type')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('leave.types.index') }}">Leave Types</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection

@section('content')
<div class="row justify-content-center">
<div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold"><i class="bi bi-calendar-x me-2"></i>New Leave Type</div>
    <div class="card-body">
        <form method="POST" action="{{ route('leave.types.store') }}">
            @csrf
            @include('leave.types._form')
            <div class="d-flex gap-2 mt-3">
                <button type="submit" class="btn btn-primary">Save</button>
                <a href="{{ route('leave.types.index') }}" class="btn btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div>
</div>
</div>
@endsection
