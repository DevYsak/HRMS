<x-mail::message>
# Welcome to {{ config('app.name') }}!

Hi **{{ $user->name }}**,

Your employee account has been created. You can now log in to the HR portal using the credentials below.

<x-mail::panel>
**Login URL:** {{ url('/login') }}

**Email:** {{ $user->email }}

**Temporary Password:** `{{ $temporaryPassword }}`
</x-mail::panel>

**Important:** Please change your password after your first login.

<x-mail::button :url="url('/login')" color="primary">
Login Now
</x-mail::button>

If you have any issues logging in, contact your HR administrator.

Thanks,
**{{ config('app.name') }} HR Team**
</x-mail::message>
