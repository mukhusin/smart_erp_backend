@extends('layouts.app')
@section('title','Edit Roster Code')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.codes.index') }}">Roster Codes</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-6">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit: {{ $code->code }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('roster.codes.update', $code) }}">
            @csrf @method('PUT')
            @include('roster.codes._form')
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('roster.codes.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
