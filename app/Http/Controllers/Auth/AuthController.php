<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\SingleDeviceSessionManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    public function __construct(private readonly SingleDeviceSessionManager $sessionManager) {}

    public function showLogin()
    {
        return view('auth.login');
    }

    public function login(Request $request)
    {
        try {
            $this->logoutAndClearSession($request);

            $validator = Validator::make($request->all(), [
                'username' => 'required|string|max:255',
                'password' => 'required|string|max:100',
            ]);

            if ($validator->fails()) {
                return back()->withErrors($validator)->onlyInput('username');
            }

            $loginValue = trim((string) $request->username);

            if (Str::contains($loginValue, '@') && ! filter_var($loginValue, FILTER_VALIDATE_EMAIL)) {
                return back()->withErrors(['username' => 'Enter a valid email address or username.'])->onlyInput('username');
            }

            $authenticated = false;

            foreach ($this->buildCredentialAttempts($loginValue, $request->password) as $credentials) {
                if (Auth::attempt($credentials, false)) {
                    $authenticated = true;
                    break;
                }
            }

            if (! $authenticated) {
                $this->logoutAndClearSession($request);
                Log::warning('Failed login attempt', ['username' => $loginValue, 'ip' => $request->ip()]);
                return back()->withErrors(['username' => 'Wrong username / email or password, or account is inactive.'])->onlyInput('username');
            }

            $request->session()->regenerate();
            $this->sessionManager->registerLogin(Auth::id(), $request->session()->getId());
            session(['last_activity' => time()]);

            return redirect()->intended(route('dashboard'));

        } catch (\Throwable $e) {
            Log::error('Login error: ' . $e->getMessage());
            return back()->withErrors(['username' => 'An error occurred. Please try again.'])->onlyInput('username');
        }
    }

    public function logout(Request $request)
    {
        $this->logoutAndClearSession($request);
        return redirect()->route('login');
    }

    protected function logoutAndClearSession(Request $request): void
    {
        if ($user = Auth::user()) {
            $this->sessionManager->clearSessionRegistration($user->id);
        }

        Auth::logout();
        $request->session()->flush();
        $request->session()->invalidate();
    }

    protected function buildCredentialAttempts(string $loginValue, string $password): array
    {
        $base = ['password' => $password, 'isActive' => true];

        if (filter_var($loginValue, FILTER_VALIDATE_EMAIL)) {
            return [
                array_merge($base, ['email'    => Str::lower($loginValue)]),
                array_merge($base, ['username' => $loginValue]),
            ];
        }

        return [
            array_merge($base, ['username' => $loginValue]),
            array_merge($base, ['email'    => Str::lower($loginValue)]),
        ];
    }
}
