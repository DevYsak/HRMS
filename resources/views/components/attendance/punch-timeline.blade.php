{{--
    A day's punches as a timeline: IN green, OUT orange. Collapsed to the latest
    $limit events (earlier ones counted as "+N"); hovering or focusing it shows
    every punch of the day with its IN / OUT label and time.

    punches: [['time' => '10:57 AM', 'direction' => 'in'|'out'|null, 'source' => 'biometric'|'regularisation'|'system_auto'|…], …]
--}}
@props(['punches' => [], 'limit' => 4, 'title' => null])

@php
    $all = array_values($punches);
    $shown = array_slice($all, -$limit, null, true);
    $hidden = count($all) - count($shown);
    $dot = fn (?string $direction) => match ($direction) {
        'in' => 'bg-emerald-500 ring-emerald-100 dark:ring-emerald-500/20',
        'out' => 'bg-orange-500 ring-orange-100 dark:ring-orange-500/20',
        default => 'bg-zinc-400 ring-zinc-100 dark:ring-white/10',
    };
    $icon = fn (?string $direction) => $direction === 'out' ? 'arrow-left-start-on-rectangle' : 'arrow-right-end-on-rectangle';
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
    <span class="text-[10px] text-zinc-400" title="No device punches stored for this day — the row's check-in / check-out are the record">—</span>
@else
    <flux:tooltip position="bottom">
        <button type="button" class="flex items-start rounded-lg px-1 py-0.5 text-left transition hover:bg-orange-50/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-300 dark:hover:bg-white/5"
            data-punch-timeline aria-label="{{ count($all) }} {{ \Illuminate\Support\Str::plural('punch', count($all)) }}: {{ collect($all)->map(fn ($p) => $label($p['direction'] ?? null).' '.$p['time'])->implode(', ') }}">
            @if($hidden > 0)
                <span class="mr-1 mt-0.5 rounded-full bg-zinc-100 px-1.5 py-0.5 text-[9px] font-bold text-zinc-500 dark:bg-white/10 dark:text-zinc-300" title="{{ $hidden }} earlier {{ \Illuminate\Support\Str::plural('punch', $hidden) }}">+{{ $hidden }}</span>
            @endif
            @foreach($shown as $p)
                @if(! $loop->first)
                    <span class="mt-[9px] h-px w-3 shrink-0 bg-zinc-200 dark:bg-white/15" aria-hidden="true"></span>
                @endif
                <span class="flex flex-col items-center" data-punch="{{ $p['direction'] ?? 'unknown' }}">
                    <span class="flex size-5 items-center justify-center rounded-full text-white ring-2 {{ $dot($p['direction'] ?? null) }}">
                        <flux:icon :name="$icon($p['direction'] ?? null)" variant="micro" class="size-3" />
                    </span>
                    <span class="mt-0.5 whitespace-nowrap font-mono text-[9px] font-bold tabular-nums text-zinc-600 dark:text-zinc-300">{{ $p['time'] }}</span>
                    <span class="sr-only">{{ $label($p['direction'] ?? null) }} {{ $p['time'] }}</span>
                </span>
            @endforeach
        </button>
        <flux:tooltip.content class="max-w-sm text-left">
            <div class="mb-1.5 flex items-center justify-between gap-4 border-b border-white/10 pb-1.5">
                <span class="text-[13px] font-semibold text-white">{{ $title ?? 'All punches' }}</span>
                <span class="text-xs text-zinc-400">{{ count($all) }} {{ \Illuminate\Support\Str::plural('punch', count($all)) }}</span>
            </div>
            <ol class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-3" data-punch-timeline-full>
                @foreach($all as $p)
                    <li class="flex items-center gap-1.5">
                        <span class="size-2 shrink-0 rounded-full {{ ($p['direction'] ?? null) === 'in' ? 'bg-emerald-400' : (($p['direction'] ?? null) === 'out' ? 'bg-orange-400' : 'bg-zinc-400') }}"></span>
                        <span class="whitespace-nowrap font-mono text-xs font-semibold text-white">{{ $label($p['direction'] ?? null) }} {{ $p['time'] }}</span>
                        @if($note($p['source'] ?? null))<span class="text-[10px] text-zinc-400">{{ $note($p['source'] ?? null) }}</span>@endif
                    </li>
                @endforeach
            </ol>
        </flux:tooltip.content>
    </flux:tooltip>
@endif
