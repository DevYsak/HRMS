@props([
    'label',
    'value',
    'icon',
    'color' => '#F97316',
    'trend' => null,      // array{txt,dir} from the comparison engine, a plain string, or null
    'caption' => null,
    'spark' => null,      // pre-built "x,y x,y …" polyline points, or null
    'comparisonLabel' => '',
])

@php
    // Last point of the sparkline gets a dot; computed here so the markup stays clean.
    $sparkLast = $spark ? explode(',', last(explode(' ', $spark))) : null;
@endphp

{{-- Premium KPI card. Every value is passed in from the Attendance Engine —
     this component only styles, it never computes. --}}
<div class="pa-kpi2" data-reveal-item>
    <div class="top">
        <span class="ic" style="background: {{ $color }}1a; color: {{ $color }};">
            <flux:icon :icon="$icon" class="size-4" />
        </span>
        @if(is_array($trend))
            <span class="tr {{ $trend['dir'] }}" title="{{ $comparisonLabel }}">{{ $trend['txt'] }}</span>
        @elseif($trend !== null && $trend !== '')
            <span class="tr">{{ $trend }}</span>
        @endif
    </div>
    <div class="v">{{ $value }}</div>
    <div class="l">{{ $label }}</div>
    @if($caption)
        <div class="cmp">{{ $caption }}</div>
    @endif
    @if($spark && $sparkLast)
        <svg class="spark" viewBox="0 0 120 28" preserveAspectRatio="none">
            <polyline points="{{ $spark }}" fill="none" stroke="{{ $color }}" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
            <circle cx="{{ $sparkLast[0] }}" cy="{{ $sparkLast[1] }}" r="2.6" fill="{{ $color }}" />
        </svg>
    @endif
</div>
