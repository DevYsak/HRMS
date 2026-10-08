{{-- One decided request in the Command Center activity feed ($f; $isLast hides the connector). --}}
@php $ok = $f['status'] === 'approved'; @endphp
<div class="relative flex items-start gap-3">
    @unless($isLast)<span class="absolute left-[9px] top-6 h-full w-px bg-orange-100 dark:bg-zinc-800"></span>@endunless
    <span class="mt-0.5 inline-flex size-5 shrink-0 items-center justify-center rounded-full {{ $ok ? 'bg-emerald-500' : 'bg-rose-500' }} text-white"><flux:icon :icon="$ok ? 'check' : 'x-mark'" class="size-3" /></span>
    <div class="min-w-0 flex-1 text-xs">
        <span class="font-black text-zinc-900 dark:text-white">{{ $f['employee'] }}</span>
        <span class="text-zinc-500 dark:text-zinc-400">— {{ $f['type'] }} {{ $f['status'] }}</span>
        <div class="text-[10px] text-zinc-400">
            @if($f['reviewer'])by {{ $f['reviewer'] }} · @endif{{ $f['at'] ? \Carbon\Carbon::parse($f['at'])->diffForHumans() : '' }}
        </div>
    </div>
</div>
