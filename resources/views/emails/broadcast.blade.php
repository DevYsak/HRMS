<x-mail::message>
{{-- Admin-composed body, escaped and rendered as a raw HTML block so line
     breaks survive and no markdown/HTML injection is possible. The composed
     body is the complete message — no signature is appended, so whoever writes
     it controls the sign-off. --}}
<div>{!! nl2br(e($body)) !!}</div>
</x-mail::message>
