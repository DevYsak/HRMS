@props([
    'title' => '',
    'subtitle' => null,
    'icon' => null,
    'accent' => 'orange',
])

@php
    $chip = [
        'orange' => 'bg-orange-50 text-orange-500', 'green' => 'bg-green-50 text-green-600',
        'amber' => 'bg-amber-50 text-amber-600', 'blue' => 'bg-blue-50 text-blue-600',
        'red' => 'bg-red-50 text-red-600', 'violet' => 'bg-violet-50 text-violet-600',
    ][$accent] ?? 'bg-orange-50 text-orange-500';
@endphp

<div {{ $attributes->class('rounded-2xl border border-[#F3E8DD] bg-white p-6 shadow-sm transition hover:shadow-lg') }}>
    @if($title)
        <div class="mb-4 flex items-center gap-3">
            @if($icon)
                <span class="flex size-9 items-center justify-center rounded-lg {{ $chip }}"><flux:icon :name="$icon" class="size-[18px]" /></span>
            @endif
            <div class="flex-1">
                <h3 class="text-lg font-bold tracking-tight text-[#111827]">{{ $title }}</h3>
                @if($subtitle)<p class="text-sm text-[#6B7280]">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </div>
    @endif
    {{ $slot }}
</div>
