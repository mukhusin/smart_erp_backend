@extends('layouts.app')
@section('title', 'Self-Service')

@section('content')
<div class="text-center py-5">
    <i class="bi bi-person-x text-muted" style="font-size:4rem"></i>
    <h4 class="mt-3">No Employee Profile Linked</h4>
    <p class="text-muted">Your user account is not linked to an employee record. Please contact HR to link your account.</p>
    <a href="{{ route('dashboard') }}" class="btn btn-primary">Back to Dashboard</a>
</div>
@endsection
