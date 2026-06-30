@props(['attendance' => null, 'shift' => null, 'workedMinutes' => 0])

@php
    $expectedMin = $shift && $shift->standard_hours ? (int) round(((float) $shift->standard_hours) * 60) : 540;
    $pct = $expectedMin > 0 ? min(100, (int) round(($workedMinutes / $expectedMin) * 100)) : 0;

    $fmt = fn ($t) => $t ? \Illuminate\Support\Carbon::parse($t)->format('h:i A') : null;

    $steps = [
        ['label' => 'Clock In',    'time' => $fmt($attendance?->check_in),    'icon' => 'arrow-right-end-on-rectangle', 'note' => 'Web Punch'],
        ['label' => 'Break Start', 'time' => $fmt($attendance?->break_start), 'icon' => 'pause-circle',                 'note' => 'Manual'],
        ['label' => 'Break End',   'time' => $fmt($attendance?->break_end),   'icon' => 'play-circle',                  'note' => 'Manual'],
        ['label' => 'Clock Out',   'time' => $fmt($attendance?->check_out),   'icon' => 'arrow-left-start-on-rectangle','note' => 'Pending'],
    ];
@endphp

<div class="space-y-1">
    @foreach($steps as $i => $s)
        <div class="flex items-center gap-3">
            <div class="flex flex-col items-center">
                <span @class([
                    'flex size-8 items-center justify-center rounded-full ring-4 transition',
                    'bg-orange-500 text-white ring-orange-100 dark:ring-orange-900/30' => $s['time'],
                    'bg-zinc-100 text-zinc-400 ring-transparent dark:bg-zinc-800' => ! $s['time'],
                ])>
                    <flux:icon :name="$s['icon']" class="size-4" />
                </span>
                @if(! $loop->last)
                    <span @class(['my-0.5 h-6 w-0.5 rounded', 'bg-orange-200 dark:bg-orange-900/40' => $s['time'], 'bg-zinc-100 dark:bg-zinc-800' => ! $s['time']])></span>
                @endif
            </div>
            <div class="flex flex-1 items-center justify-between pb-2">
                <div>
                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $s['label'] }}</div>
                    <div class="text-[11px] text-zinc-400">{{ $s['note'] }}</div>
                </div>
                <div class="text-sm font-semibold tabular-nums {{ $s['time'] ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-300 dark:text-zinc-600' }}">
                    {{ $s['time'] ?? '— : —' }}
                </div>
            </div>
        </div>
    @endforeach

    <div class="mt-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/50">
        <div class="mb-1.5 flex items-center justify-between text-[11px] font-semibold">
            <span class="text-zinc-500">Working {{ intdiv($workedMinutes, 60) }}h {{ $workedMinutes % 60 }}m</span>
            <span class="text-zinc-400">Expected {{ intdiv($expectedMin, 60) }}h</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700">
            <div class="h-full rounded-full bg-gradient-to-r from-orange-500 to-amber-400 transition-all duration-1000" style="width: {{ $pct }}%"></div>
        </div>
    </div>
</div>
