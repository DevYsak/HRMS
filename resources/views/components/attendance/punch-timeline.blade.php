{{--
    A day's punches, compact: "6 punches" next to the day's timeline bar.
    Hovering or focusing it lists every punch of the day in order with its
    IN / OUT label and time (and what a regularised, automatic or web punch
    was). The full story of the day is the Day history.

    punches: [['time' => '10:57 AM', 'direction' => 'in'|'out'|null, 'source' => 'biometric'|'regularisation'|'system_auto'|…], …]
--}}
@props(['punches' => [], 'title' => null])

@php
    $all = array_values($punches);
    $label = fn (?string $direction) => $direction === null ? '?' : strtoupper($direction);
    $note = fn (?string $source) => match ($source) {
        'regularisation' => 'Regularised',
        'system_auto' => 'Automatic punch-out',
        'web', 'mobile' => 'Web / mobile',
        'manual' => 'Marked by HR',
        default => null,
    };
@endphp

@if($all === [])
    <span class="text-xs text-zinc-400" title="No device punches stored for this day — the row's check-in / check-out are the record">No punches</span>
@else
    <flux:tooltip position="bottom">
        <button type="button" class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 text-xs font-medium text-zinc-500 transition hover:bg-orange-50 hover:text-orange-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 dark:text-zinc-400 dark:hover:bg-white/5 dark:hover:text-orange-300"
            data-punch-timeline aria-label="{{ count($all) }} {{ \Illuminate\Support\Str::plural('punch', count($all)) }}: {{ collect($all)->map(fn ($p) => $label($p['direction'] ?? null).' '.$p['time'])->implode(', ') }}">
            <flux:icon.finger-print variant="micro" class="size-3.5" />
            {{ count($all) }} {{ \Illuminate\Support\Str::plural('punch', count($all)) }}
        </button>
        <flux:tooltip.content class="max-w-sm text-left">
            <div class="mb-1.5 flex items-center justify-between gap-4 border-b border-white/10 pb-1.5">
                <span class="text-[13px] font-semibold text-white">{{ $title ?? 'All punches' }}</span>
                <span class="text-xs text-zinc-400">{{ count($all) }} {{ \Illuminate\Support\Str::plural('punch', count($all)) }}</span>
            </div>
            <ol class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3" data-punch-timeline-full>
                @foreach($all as $p)
                    <li class="flex items-center gap-1.5" data-punch="{{ $p['direction'] ?? 'unknown' }}">
                        <span class="size-2 shrink-0 rounded-full {{ ($p['direction'] ?? null) === 'in' ? 'bg-emerald-400' : (($p['direction'] ?? null) === 'out' ? 'bg-orange-400' : 'bg-zinc-400') }}"></span>
                        <span class="whitespace-nowrap text-xs font-semibold tabular-nums text-white">{{ $label($p['direction'] ?? null) }} {{ $p['time'] }}</span>
                        @if($note($p['source'] ?? null))<span class="text-[11px] text-zinc-400">{{ $note($p['source'] ?? null) }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </flux:tooltip.content>
    </flux:tooltip>
@endif
