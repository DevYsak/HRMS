@props([
    'for' => null,
    // Mark only what the server's validation actually requires.
    'required' => false,
    'optional' => false,
])

{{-- The one form label: a required field gets a red asterisk (plus screen
     reader text), an optional one says so. Pair the input with aria-required. --}}
<label @if($for) for="{{ $for }}" @endif data-flux-label
    {{ $attributes->merge(['class' => 'mb-1.5 inline-flex flex-wrap items-baseline gap-x-1 text-sm font-medium text-zinc-800 dark:text-white']) }}>
    <span>{{ $slot }}</span>
    @if($required)
        <span class="text-red-600 dark:text-red-400" aria-hidden="true" data-required-marker>*</span>
        <span class="sr-only">({{ __('required') }})</span>
    @elseif($optional)
        <span class="text-xs font-normal text-zinc-400">({{ __('Optional') }})</span>
    @endif
</label>
