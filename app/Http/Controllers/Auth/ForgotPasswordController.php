<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\SendOtpMail;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ForgotPasswordController extends Controller
{
    public function showForgotPasswordForm()
    {
        return view('auth.forgot-password');
    }

    public function sendOtpEmail(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        $user = User::where('email', $request->email)->first();
        $otp  = rand(100000, 999999);

        $user->otp        = $otp;
        $user->otp_expiry = now()->addMinutes(10)->toDateTimeString();
        $user->save();

        Mail::to($user->email)->send(new SendOtpMail($otp));

        return redirect()->route('password.reset.form')
            ->with('status', 'A 6-digit OTP has been sent to your email. It expires in 10 minutes.');
    }

    public function showResetPasswordForm()
    {
        return view('auth.reset-password');
    }

    public function resetPassword(Request $request)
    {
        $request->validate([
            'email'                 => 'required|email|exists:users,email',
            'otp'                   => 'required|digits:6',
            'password'              => 'required|confirmed|min:8',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || $user->otp != $request->otp || now()->gt($user->otp_expiry)) {
            return back()->withErrors(['otp' => 'Invalid or expired OTP.'])->withInput();
        }

        $user->password   = bcrypt($request->password);
        $user->otp        = null;
        $user->otp_expiry = null;
        $user->save();

        return redirect()->route('login')
            ->with('status', 'Password reset successfully. You can now sign in.');
    }
}
