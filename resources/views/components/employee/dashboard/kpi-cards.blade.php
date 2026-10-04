@props(['kpis'])

{{-- EmployeeKpiCards — eight compact figures. Icons stay neutral; colour is
     reserved for a value that needs attention (late, absent, overdrawn). --}}
<section aria-label="Key figures" class="grid grid-cols-2 gap-3 md:grid-cols-4 2xl:grid-cols-8">
    @foreach($kpis as $kpi)
        @php
            $valueTone = match ($kpi['tone']) {
                'success' => 'text-emerald-700 dark:text-emerald-400',
                'warning' => 'text-amber-700 dark:text-amber-400',
                'danger' => 'text-rose-600 dark:text-rose-400',
                default => 'text-zinc-900 dark:text-white',
            };
        @endphp
        <a href="{{ $kpi['href'] }}" wire:navigate
           class="group flex min-w-0 flex-col rounded-xl border border-zinc-200/70 bg-white p-4 shadow-[0_1px_2px_0_rgb(16_24_40/0.04)] transition hover:border-zinc-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 dark:border-white/[0.06] dark:bg-ink-900 dark:hover:border-white/15">
            <span class="flex items-center justify-between gap-2">
                <span class="truncate text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $kpi['label'] }}</span>
                <flux:icon :name="$kpi['icon']" class="size-4 shrink-0 text-zinc-300 transition group-hover:text-orange-500 dark:text-zinc-600" />
            </span>
            <span class="mt-2 truncate text-xl font-semibold tabular-nums tracking-tight {{ $valueTone }}">{{ $kpi['value'] }}</span>
            <span class="mt-0.5 truncate text-xs text-zinc-500 dark:text-zinc-400" title="{{ $kpi['sub'] }}">{{ $kpi['sub'] }}</span>
        </a>
    @endforeach
</section>
