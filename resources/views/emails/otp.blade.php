<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Password Reset OTP</title></head>
<body style="font-family:sans-serif;background:#f4f4f4;margin:0;padding:24px">
<div style="max-width:520px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08)">
    <div style="background:#2563eb;padding:24px 32px">
        <h2 style="color:#fff;margin:0;font-size:1.25rem">{{ config('app.name') }}</h2>
    </div>
    <div style="padding:32px">
        <h3 style="margin-top:0">Password Reset Request</h3>
        <p>Use the OTP below to reset your password. It expires in <strong>10 minutes</strong>.</p>
        <div style="font-size:2.5rem;font-weight:700;letter-spacing:12px;color:#2563eb;text-align:center;padding:20px 0">
            {{ $otp }}
        </div>
        <p style="color:#6b7280;font-size:.875rem">If you did not request this, ignore this email — your account remains secure.</p>
    </div>
</div>
</body>
</html>
