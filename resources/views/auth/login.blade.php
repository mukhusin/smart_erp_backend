@extends('layouts.auth')
@section('title', 'Sign In')

@section('content')
<div class="auth-card">
    <div class="text-center mb-4">
        <div class="sidebar-brand-icon mx-auto mb-3" style="width:52px;height:52px;font-size:1.5rem">
            <i class="bi bi-grid-3x3-gap-fill"></i>
        </div>
        <h4 class="fw-bold mb-1">{{ config('app.name') }}</h4>
        <p class="text-muted small">Sign in to your account</p>
    </div>

    @if(session('warning'))
        <div class="alert alert-warning py-2 small"><i class="bi bi-exclamation-triangle me-1"></i>{{ session('warning') }}</div>
    @endif

    @if ($errors->any())
        <div class="alert alert-danger py-2 small"><i class="bi bi-x-circle me-1"></i>{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <div class="mb-3">
            <label class="form-label" for="username">Username or Email</label>
            <input id="username" type="text" name="username"
                   class="form-control @error('username') is-invalid @enderror"
                   value="{{ old('username') }}" required autofocus autocomplete="username">
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
                   required autocomplete="current-password">
            <div id="capsLockWarning" class="text-warning small mt-1" style="display:none">
                <i class="bi bi-exclamation-triangle me-1"></i>Caps Lock is ON
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2">
            <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
        </button>

        <p class="text-center text-muted mt-3 mb-0" style="font-size:12px">
            <i class="bi bi-shield-lock me-1"></i>Single device access — logging in will end sessions on other devices.
        </p>
    </form>
</div>

@push('scripts')
<script>
document.getElementById('password').addEventListener('keyup', function(e) {
    document.getElementById('capsLockWarning').style.display = e.getModifierState('CapsLock') ? 'block' : 'none';
});
</script>
@endpush
@endsection
