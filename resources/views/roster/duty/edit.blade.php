@extends('layouts.app')
@section('title','Edit Duty Roster')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.duty.index') }}">Duty Rosters</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-7">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit: {{ $duty->title }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('roster.duty.update', $duty) }}">
            @csrf @method('PUT')
            @include('roster.duty._form')
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('roster.duty.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
