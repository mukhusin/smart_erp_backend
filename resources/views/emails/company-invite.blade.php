<x-mail::message>
# Welcome to {{ config('app.name') }}

Hello {{ $user->display_name }},

An account has been created for **{{ $company->name }}**. You are the administrator for your organization:
you can register your staff, branches, departments and give access to others.

Your username is **{{ $user->username }}**. Set your password to get started:

<x-mail::button :url="$url">
Set my password
</x-mail::button>

This link expires in {{ $days }} days. If it expires, ask the system administrator to send a new one.

Thanks,<br>
{{ config('app.name') }}
</x-mail::message>
