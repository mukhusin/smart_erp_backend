@extends('layouts.app')
@section('title','New Allowance Category')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.categories.index') }}">Categories</a></li>
    <li class="breadcrumb-item active">New</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">New Allowance Category</div>
    <div class="card-body">
        <form method="POST" action="{{ route('allowance.categories.store') }}">
            @csrf
            @include('allowance.categories._form')
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Save</button><a href="{{ route('allowance.categories.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
