@props([
    'cols' => 4,        // 2 | 3 | 4 columns from the lg breakpoint up (4-up shows 2-up on phones)
    'split' => false,   // true: 65% / 35% two-column layout from xl up (the sidebar leaves too little room below that)
])

@php
    $class = $split
        ? 'grid-cols-1 xl:grid-cols-[minmax(0,13fr)_minmax(0,7fr)]'
        : match ((int) $cols) {
            2 => 'grid-cols-1 lg:grid-cols-2',
            3 => 'grid-cols-1 md:grid-cols-2 lg:grid-cols-3',
            default => 'grid-cols-2 lg:grid-cols-4',
        };
@endphp

{{-- Standard dashboard grid: one gap (16px) everywhere. --}}
<div {{ $attributes->class("grid min-w-0 gap-4 $class") }}>{{ $slot }}</div>
