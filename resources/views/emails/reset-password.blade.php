<x-mail::message>
# Reset your password

Hello {{ $user->display_name }},

We received a request to reset the password for **{{ $user->username }}**.

<x-mail::button :url="$url">
Choose a new password
</x-mail::button>

This link expires in {{ $minutes }} minutes. If you did not ask for this, you can ignore this email: your password stays the same.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
