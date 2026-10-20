@props([
    'model',             // Livewire property holding 'Y-m' or '' (all months)
    'value' => '',       // its current value, so an older selection stays listed
    'months' => 24,      // how many recent months to offer, newest first
    'allLabel' => 'All months',
])

{{-- List-filter month picker. A native <input type="month"> shows
     "--------- ----" while empty (the default "all months" state);
     this offers "All months" plus recent months with the same 'Y-m' value. --}}
@php
    $start = now()->startOfMonth();
    $monthOptions = collect(range(0, max($months - 1, 0)))
        ->map(fn ($i) => $start->copy()->subMonths($i))
        ->map(fn ($m) => ['value' => $m->format('Y-m'), 'label' => $m->format('M Y')]);

    // Keep a selection older than the window visible (e.g. a deep link).
    $current = (string) $value;
    if (preg_match('/^\d{4}-\d{2}$/', $current) && ! $monthOptions->contains('value', $current)) {
        $monthOptions->push(['value' => $current, 'label' => \Illuminate\Support\Carbon::createFromFormat('Y-m-d', $current.'-01')->format('M Y')]);
    }
@endphp

<flux:select wire:model.live="{{ $model }}" size="sm" {{ $attributes->merge(['class' => 'w-40', 'data-month-filter' => true, 'aria-label' => 'Filter by month']) }}>
    <option value="">{{ $allLabel }}</option>
    @foreach($monthOptions as $option)
        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
    @endforeach
</flux:select>
