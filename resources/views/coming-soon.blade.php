@extends('layouts.app')
@section('title', 'Coming Soon')

@section('breadcrumb')
    <li class="breadcrumb-item active">Coming Soon</li>
@endsection

@section('content')
<div class="text-center py-5 my-5">
    <i class="bi bi-tools display-3 text-muted opacity-50 d-block mb-3"></i>
    <h4 class="fw-bold">Module Under Development</h4>
    <p class="text-muted">This section is being built. Check back soon.</p>
    <a href="{{ route('dashboard') }}" class="btn btn-primary mt-2">
        <i class="bi bi-arrow-left me-1"></i> Back to Dashboard
    </a>
</div>
@endsection
