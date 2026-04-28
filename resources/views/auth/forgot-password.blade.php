@extends('layouts.auth')
@section('title', 'Forgot Password')

@section('content')
<div class="auth-card">
    <div class="text-center mb-4">
        <div class="sidebar-brand-icon mx-auto mb-3" style="width:52px;height:52px;font-size:1.5rem">
            <i class="bi bi-key-fill"></i>
        </div>
        <h4 class="fw-bold mb-1">Forgot Password</h4>
        <p class="text-muted small">Enter your email to receive a one-time password.</p>
    </div>

    @if(session('status'))
        <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i>{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.send-otp') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="email">Email Address</label>
            <input id="email" type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}" required autofocus autocomplete="email">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-send me-1"></i> Send OTP
        </button>
    </form>

    <div class="text-center small">
        <a href="{{ route('login') }}" class="text-muted"><i class="bi bi-arrow-left me-1"></i>Back to login</a>
    </div>
</div>
@endsection
