@props([
    'size' => 'h-12 w-auto',   // sizing utilities for the <img> itself
    'href' => null,            // wrap in a link when given
    'invertOnDark' => true,    // see note below
    'variant' => 'logo',       // 'logo' = full wordmark, 'mark' = square icon for the folded sidebar
])

{{--
    The company logo, rendered at its natural proportions.

    Deliberately has no box, background, border, radius, shadow or padding of
    its own — the image is the brand mark, and a wrapper would crop or shrink
    a wide wordmark. Sizing is the caller's choice because the right constraint
    differs by surface: the sidebar is narrow so it sizes by width, while the
    login panel has room to size by height.

    Each theme has its own artwork (Settings → General: light logo, dark logo
    and the two sidebar icons, PNG or sanitised SVG). When a company uploads a
    light logo but no dark one, `brightness-0 invert` repaints the light one
    white in dark mode so it never disappears on a dark surface; pass
    :invert-on-dark="false" on a surface that is light in both themes.

    With no icon at all (an uploaded logo, no mark) the company's initial
    stands in on the folded rail.
--}}
@php
    $isMark = $variant === 'mark';
    $light = $isMark ? App\Models\Company::brandMarkUrl() : App\Models\Company::brandLogoUrl();
    $dark = $isMark ? App\Models\Company::brandMarkDarkUrl() : App\Models\Company::brandLogoDarkUrl();
    $alt = App\Models\Company::brandName();

    $base = trim($size.' max-w-full object-contain select-none '.($isMark ? 'object-center' : 'object-left'));
    $themed = $invertOnDark && $dark !== null;
    $singleClass = $base.($invertOnDark && $dark === null ? ' dark:brightness-0 dark:invert' : '');
@endphp

@if($href)
    <a href="{{ $href }}" {{ $attributes->class('inline-flex min-w-0 items-center') }}>
@endif

@if($light === null)
    <span @unless($href) {{ $attributes->class($size.' inline-flex items-center justify-center rounded-lg bg-orange-500 text-sm font-bold text-white select-none') }} @else class="{{ $size }} inline-flex items-center justify-center rounded-lg bg-orange-500 text-sm font-bold text-white select-none" @endunless
        role="img" aria-label="{{ $alt }}">{{ mb_strtoupper(mb_substr($alt, 0, 1)) }}</span>
@elseif($themed)
    <img src="{{ $light }}" alt="{{ $alt }}" @unless($href) {{ $attributes->class($base.' dark:hidden') }} @else class="{{ $base }} dark:hidden" @endunless>
    <img src="{{ $dark }}" alt="{{ $alt }}" @unless($href) {{ $attributes->class($base.' hidden dark:block') }} @else class="{{ $base }} hidden dark:block" @endunless>
@else
    <img src="{{ $light }}" alt="{{ $alt }}" @unless($href) {{ $attributes->class($singleClass) }} @else class="{{ $singleClass }}" @endunless>
@endif

@if($href)
    </a>
@endif
