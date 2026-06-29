@props([
    'options' => [],   // full ApexCharts config (array → JSON)
    'id' => null,      // optional id → live-updatable via 'apex-update' window event
])

{{-- wire:ignore so Livewire never touches the canvas Apex owns --}}
<div wire:ignore {{ $attributes }}>
    <div x-data="apexChart(@js($id), @js($options))" x-ref="chart"></div>
</div>
