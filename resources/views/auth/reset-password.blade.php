@extends('layouts.auth')
@section('title', 'Reset Password')

@section('content')
<div class="auth-card">
    <div class="text-center mb-4">
        <div class="sidebar-brand-icon mx-auto mb-3" style="width:52px;height:52px;font-size:1.5rem">
            <i class="bi bi-shield-lock-fill"></i>
        </div>
        <h4 class="fw-bold mb-1">Reset Password</h4>
        <p class="text-muted small">Enter the OTP sent to your email and choose a new password.</p>
    </div>

    @if(session('status'))
        <div class="alert alert-success py-2 small"><i class="bi bi-check-circle me-1"></i>{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i>{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('password.do-reset') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="email">Email Address</label>
            <input id="email" type="email" name="email"
                   class="form-control @error('email') is-invalid @enderror"
                   value="{{ old('email') }}" required autocomplete="email">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="otp">6-Digit OTP</label>
            <input id="otp" type="text" name="otp" inputmode="numeric" maxlength="6"
                   class="form-control @error('otp') is-invalid @enderror"
                   value="{{ old('otp') }}" required placeholder="______">
            @error('otp')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-3">
            <label class="form-label" for="password">New Password</label>
            <input id="password" type="password" name="password"
                   class="form-control @error('password') is-invalid @enderror"
                   required autocomplete="new-password">
            @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="mb-4">
            <label class="form-label" for="password_confirmation">Confirm New Password</label>
            <input id="password_confirmation" type="password" name="password_confirmation"
                   class="form-control" required autocomplete="new-password">
        </div>

        <button type="submit" class="btn btn-primary w-100 mb-3">
            <i class="bi bi-check-circle me-1"></i> Reset Password
        </button>
    </form>

    <div class="text-center small">
        <a href="{{ route('password.request') }}" class="text-muted me-3">
            <i class="bi bi-arrow-left me-1"></i>Request new OTP
        </a>
        <a href="{{ route('login') }}" class="text-muted">Sign in</a>
    </div>
</div>
@endsection
