@props([
    'sidebar' => false,
])

@if($sidebar)
    <flux:sidebar.brand name="Pulse" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-600">
            {{-- Pulse "H" icon matching Figma --}}
            <svg class="size-5 fill-white" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/>
            </svg>
        </x-slot>
    </flux:sidebar.brand>
@else
    <flux:brand name="Pulse" {{ $attributes }}>
        <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-md bg-brand-600">
            <svg class="size-5 fill-white" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                <path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/>
            </svg>
        </x-slot>
    </flux:brand>
@endif
