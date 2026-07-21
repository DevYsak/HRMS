<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - '.config('app.name', 'Pulse') : config('app.name', 'Pulse') }}
</title>

<meta name="description" content="Pulse HRMS - Human Resource Management System by Conexus" />

@php
    // Company-configurable favicon (Settings → Company). Cached so the <head>
    // doesn't hit the DB on every request; rescue() keeps error pages rendering
    // even when the DB is unavailable. Cleared on save in the settings screen.
    $companyFavicon = rescue(
        fn () => \Illuminate\Support\Facades\Cache::remember(
            'company.favicon', now()->addHours(6),
            fn () => \App\Models\Company::query()->value('favicon')
        ),
        null, false
    );
@endphp
@if($companyFavicon)
    <link rel="icon" href="{{ asset('storage/'.$companyFavicon) }}">
    <link rel="apple-touch-icon" href="{{ asset('storage/'.$companyFavicon) }}">
@else
    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="apple-touch-icon" href="/icon-192x192.png">
@endif
<meta name="theme-color" content="#ffffff">

{{-- Inter font from Google Fonts --}}
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet" />

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
