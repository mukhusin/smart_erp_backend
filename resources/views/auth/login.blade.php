@extends('layouts.auth')
@section('title', 'Login')

@section('content')
<div class="auth-card">
    <div class="text-center mb-4">
        <div class="sidebar-brand-icon mx-auto mb-3" style="width:52px;height:52px;font-size:1.5rem">
            <i class="bi bi-grid-3x3-gap-fill"></i>
        </div>
        <h4 class="fw-bold mb-1">{{ config('app.name') }}</h4>
        <p class="text-muted small">Sign in to your account</p>
    </div>

    @if ($errors->any())
        <div class="alert alert-danger py-2">
            <i class="bi bi-exclamation-triangle me-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="email">Email address</label>
            <input id="email" type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}" required autofocus>
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <label class="form-label" for="password">Password</label>
                @if (Route::has('password.request'))
                    <a href="{{ route('password.request') }}" class="small text-primary">Forgot password?</a>
                @endif
            </div>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required>
        </div>

        <div class="mb-3 form-check">
            <input class="form-check-input" type="checkbox" name="remember" id="remember">
            <label class="form-check-label" for="remember">Remember me</label>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">
            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
        </button>
    </form>
</div>
@endsection
