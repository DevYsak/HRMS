<x-mail::message>
# Welcome to {{ config('app.name') }} HRMS

Hi **{{ $user->name }}**,

Your HR team has invited you to {{ config('app.name') }} HRMS, where you will book time off, view your payslips and record your attendance.

Use the details below to sign in for the first time.

<x-mail::panel>
**Email:** {{ $user->email }}

**Temporary password:** `{{ $temporaryPassword }}`
</x-mail::panel>

<x-mail::button :url="$acceptUrl" color="primary">
Accept invitation and sign in
</x-mail::button>

This invitation expires on **{{ $expiresAt->format('l j F Y \a\t g:ia') }}** — {{ $expiryHours }} hours from when it was sent. After that the link stops working and your HR team will need to send a new one.

If the button does not work, sign in directly at [{{ $loginUrl }}]({{ $loginUrl }}) with the email and password above.

**Keep this password to yourself.** Nobody from HR or IT will ever ask you for it. Once you are signed in you can change it at any time from Settings → Security.

If you were not expecting this invitation, please contact your HR administrator before signing in.

Thanks,
**{{ config('app.name') }} HR Team**
</x-mail::message>
