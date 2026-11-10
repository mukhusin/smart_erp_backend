<x-mail::message>
# Welcome to {{ $company->name }}

Hello {{ $user->display_name }},

Your account on {{ config('app.name') }} is ready. Your username is **{{ $user->username }}**.

Set your password to get started:

<x-mail::button :url="$url">
Set my password
</x-mail::button>

This link expires in {{ $days }} days. If it expires, ask HR to send a new one.
After setting your password, log in at [{{ $loginUrl }}]({{ $loginUrl }}).

Thanks,<br>
{{ $company->name }}
</x-mail::message>
