<x-mail::message>
{{-- Admin-composed body. Escaped and rendered as a raw HTML block so line
     breaks survive and no markdown/HTML injection is possible. --}}
<div>{!! nl2br(e($body)) !!}</div>

Thanks,<br>
**{{ config('app.name') }} Team**
</x-mail::message>
