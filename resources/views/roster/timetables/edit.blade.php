@extends('layouts.app')
@section('title','Edit Timetable')
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('roster.timetables.index') }}">Timetables</a></li>
    <li class="breadcrumb-item active">Edit</li>
@endsection
@section('content')
<div class="row justify-content-center"><div class="col-lg-8">
<div class="card shadow-sm">
    <div class="card-header fw-semibold">Edit: {{ $timetable->title }}</div>
    <div class="card-body">
        <form method="POST" action="{{ route('roster.timetables.update', $timetable) }}">
            @csrf @method('PUT')
            @include('roster.timetables._form')
            <div class="d-flex gap-2 mt-3"><button class="btn btn-primary">Update</button><a href="{{ route('roster.timetables.index') }}" class="btn btn-secondary">Cancel</a></div>
        </form>
    </div>
</div>
</div></div>
@endsection
