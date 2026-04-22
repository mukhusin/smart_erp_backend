@extends('layouts.app')
@section('title','Edit Category')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('allowance.categories.index') }}">Categories</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit: {{ $category->category }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('allowance.categories.update', $category) }}">
            @csrf @method('PUT')
            @include('allowance.categories._form')
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('allowance.categories.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
