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
@php
    $companyFaviconUrl = $companyFavicon ? \App\Models\Company::brandFaviconUrl($companyFavicon) : null;
@endphp
@if($companyFaviconUrl)
    <link rel="icon" href="{{ $companyFaviconUrl }}">
    <link rel="apple-touch-icon" href="{{ $companyFaviconUrl }}">
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

{{-- Company look and feel (Settings → Brand & Layout): only values changed
     from the default design produce CSS; a bad row falls back silently. --}}
@php($uiTheme = app(\App\Services\Appearance\UiTheme::class))
@if($uiFontUrl = rescue(fn () => $uiTheme->fontUrl(), null, false))
    <link href="{{ $uiFontUrl }}" rel="stylesheet" />
@endif
@if(($uiCss = $uiTheme->css()) !== '')
    <style id="ui-theme">{!! $uiCss !!}</style>
@endif
