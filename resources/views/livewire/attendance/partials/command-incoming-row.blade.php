{{-- One incoming request ($row) — opens its queue tab. Needs $typeStyles. --}}
@php [$rIcon, $rColor] = $typeStyles[$row['type']] ?? ['inbox', '#71717a']; @endphp
<button type="button" wire:click="$set('tab', '{{ $row['tab'] }}')"
    class="group flex items-center gap-3 bg-white px-5 py-3 text-left transition hover:bg-orange-50/50 dark:bg-zinc-900 dark:hover:bg-zinc-800/40">
    <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl" style="background: {{ $rColor }}1a; color: {{ $rColor }};"><flux:icon :icon="$rIcon" class="size-4.5" /></span>
    <div class="min-w-0 flex-1">
        <div class="flex flex-wrap items-center gap-x-2 gap-y-0.5">
            <span class="text-xs font-black text-zinc-900 dark:text-white">{{ $row['employee'] }}</span>
            <span class="rounded-full px-1.5 py-0.5 text-[9px] font-bold" style="background: {{ $rColor }}1a; color: {{ $rColor }};">{{ $row['type'] }}</span>
        </div>
        <p class="mt-0.5 truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $row['detail'] }}</p>
    </div>
    <div class="shrink-0 text-right">
        <p class="text-[10px] font-semibold text-zinc-400">{{ $row['at'] ? \Carbon\Carbon::parse($row['at'])->diffForHumans(short: true) : '' }}</p>
        <span class="text-[10px] font-bold text-zinc-300 transition group-hover:text-orange-500">Review →</span>
    </div>
</button>
