@props([
    'start',                       // wire:model name of the start date
    'end',                         // wire:model name of the end date
    'startLabel' => __('Start date'),
    'endLabel' => __('End date'),
    'required' => false,
    'live' => false,               // wire:model.live, for forms that preview as you pick
    'min' => null,
    'max' => null,
])

@php
    $startId = 'dr-'.str_replace('.', '-', $start);
    $endId = 'dr-'.str_replace('.', '-', $end);
@endphp

{{-- One date-range field for every form: side by side from sm up, stacked on
     phones, each date's own error directly under it. The end date's minimum
     follows the chosen start date; the server still validates end ≥ start. --}}
<div {{ $attributes->merge(['class' => 'grid grid-cols-1 gap-4 sm:grid-cols-2']) }} data-date-range
    x-data="{ startValue: $wire.entangle(@js($start)) }">
    <div class="min-w-0">
        <x-pulse.label :for="$startId" :required="$required">{{ $startLabel }}</x-pulse.label>
        @if($live)
            <flux:input :id="$startId" type="date" wire:model.live="{{ $start }}" :min="$min" :max="$max"
                :aria-required="$required ? 'true' : null" :aria-invalid="$errors->has($start) ? 'true' : null" />
        @else
            <flux:input :id="$startId" type="date" wire:model="{{ $start }}" :min="$min" :max="$max"
                :aria-required="$required ? 'true' : null" :aria-invalid="$errors->has($start) ? 'true' : null" />
        @endif
        <flux:error :name="$start" />
    </div>
    <div class="min-w-0">
        <x-pulse.label :for="$endId" :required="$required">{{ $endLabel }}</x-pulse.label>
        @if($live)
            <flux:input :id="$endId" type="date" wire:model.live="{{ $end }}" x-bind:min="startValue || @js($min)" :max="$max"
                :aria-required="$required ? 'true' : null" :aria-invalid="$errors->has($end) ? 'true' : null" />
        @else
            <flux:input :id="$endId" type="date" wire:model="{{ $end }}" x-bind:min="startValue || @js($min)" :max="$max"
                :aria-required="$required ? 'true' : null" :aria-invalid="$errors->has($end) ? 'true' : null" />
        @endif
        <flux:error :name="$end" />
    </div>
</div>
