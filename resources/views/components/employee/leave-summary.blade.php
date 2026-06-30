@props(['balances', 'remaining' => 0, 'allocated' => 0, 'used' => 0])

@php
    $palette = ['#f97316', '#10b981', '#6366f1', '#f59e0b', '#06b6d4', '#ec4899'];
@endphp

<div class="flex flex-col items-center">
    {{-- Donut (initialised by the dashboard ApexCharts script) --}}
    <div class="relative">
        <div wire:ignore id="emp-leave-donut" class="h-[180px] w-[180px]"></div>
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center">
            <span class="text-3xl font-black text-zinc-900 dark:text-white">{{ (int) $remaining }}</span>
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Days Left</span>
        </div>
    </div>

    <div class="mt-4 w-full space-y-2.5">
        @forelse($balances as $i => $b)
            @php
                $total = (float) ($b->allocated_days ?? 0);
                $u = (float) ($b->used_days ?? 0);
                $rem = max(0, $total - $u);
                $color = $palette[$i % count($palette)];
            @endphp
            <div class="flex items-center justify-between text-sm">
                <span class="flex items-center gap-2 text-zinc-600 dark:text-zinc-300">
                    <span class="size-2.5 rounded-full" style="background: {{ $color }}"></span>
                    {{ $b->leaveType?->name ?? 'Leave' }}
                </span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ rtrim(rtrim((string) $rem, '0'), '.') }} <span class="text-xs font-normal text-zinc-400">/ {{ rtrim(rtrim((string) $total, '0'), '.') }}</span></span>
            </div>
        @empty
            <p class="py-2 text-center text-xs text-zinc-400">No leave balances configured.</p>
        @endforelse
    </div>

    <a href="{{ route('time-off.my') }}" wire:navigate
       class="mt-4 inline-flex w-full items-center justify-center gap-2 rounded-xl bg-orange-500 px-4 py-2.5 text-sm font-bold text-white shadow-sm transition hover:bg-orange-600">
        <flux:icon.plus class="size-4" /> Apply Leave
    </a>
</div>
