@props([
    'size' => 'h-12 w-auto',   // sizing utilities for the <img> itself
    'href' => null,            // wrap in a link when given
    'invertOnDark' => true,    // see note below
])

{{--
    The company logo, rendered at its natural proportions.

    Deliberately has no box, background, border, radius, shadow or padding of
    its own — the image is the brand mark, and a wrapper would crop or shrink
    a wide wordmark. Sizing is the caller's choice because the right constraint
    differs by surface: the sidebar is narrow so it sizes by width, while the
    login panel has room to size by height.

    The mark is black, so on a dark surface it would disappear. `brightness-0
    invert` repaints it solid white in dark mode. That loses the red accent in
    the X, which is the trade for staying legible; pass :invert-on-dark="false"
    on a surface that is light in both themes.

    The source comes from Company::brandLogoUrl(), so uploading a new logo in
    Settings → General updates every surface at once.
--}}
@php
    $src = App\Models\Company::brandLogoUrl();
    $alt = App\Models\Company::brandName();

    $imgClass = trim($size.' max-w-full object-contain object-left select-none'
        .($invertOnDark ? ' dark:brightness-0 dark:invert' : ''));
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class('inline-flex min-w-0 items-center') }}>
        <img src="{{ $src }}" alt="{{ $alt }}" class="{{ $imgClass }}">
    </a>
@else
    <img src="{{ $src }}" alt="{{ $alt }}" {{ $attributes->class($imgClass) }}>
@endif
