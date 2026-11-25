{{--
    One day as a timeline bar (an AttendanceRegister line): the shift's hours
    as a shaded band, each worked stretch from IN to OUT (green; pulsing while
    live; amber striped when the auto check-out closed it; red dashed when the
    check-out is missing) and each missed punch as a red diamond (violet once
    corrected). The large size (Day history) also marks every punch: IN
    above the bar, OUT below.

    Needs $line, $window (['from' => minutes, 'to' => minutes] from the date's
    midnight), $ticks (hour gridlines) and $clockAt; $size is 'sm' or 'lg'.
--}}
@php
    $barSpan = max(1, $window['to'] - $window['from']);
    $barPos = fn (int $m): float => round(max(0, min(100, ($m - $window['from']) * 100 / $barSpan)), 3);
    $barLarge = ($size ?? 'sm') === 'lg';
    $segmentClass = [
        'work' => 'bg-emerald-500 dark:bg-emerald-400',
        'live' => 'bg-emerald-400 animate-pulse dark:bg-emerald-300',
        'auto' => 'bg-amber-400 bg-[repeating-linear-gradient(135deg,transparent_0_4px,rgba(255,255,255,.5)_4px_8px)]',
        'open' => 'border border-dashed border-rose-400 bg-rose-100/80 dark:border-rose-400/70 dark:bg-rose-500/15',
    ];
    $segmentLabel = ['work' => 'Worked', 'live' => 'Working now', 'auto' => 'Closed by the auto check-out', 'open' => 'No check-out after'];
    $barDuration = fn (int $m): string => $m >= 60 ? intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m' : $m.'m';
@endphp

<div @class(['relative', 'py-3' => $barLarge])>
    <div @class(['relative w-full bg-zinc-100 dark:bg-white/5', 'h-2.5 rounded-full' => ! $barLarge, 'h-7 rounded-lg' => $barLarge]) data-day-bar>
        @foreach($ticks as $tick)
            <span class="absolute inset-y-0 w-px bg-white dark:bg-zinc-900/70" style="left: {{ $tick['pct'] }}%" aria-hidden="true"></span>
        @endforeach

        @if($line['shift_from'] !== null)
            <span @class(['absolute inset-y-0 bg-orange-200/70 ring-1 ring-inset ring-orange-300/70 dark:bg-orange-500/15 dark:ring-orange-400/30', 'rounded-full' => ! $barLarge, 'rounded-lg' => $barLarge])
                  style="left: {{ $barPos($line['shift_from']) }}%; width: {{ max(0, $barPos($line['shift_to']) - $barPos($line['shift_from'])) }}%"
                  title="Shift {{ $clockAt($line['shift_from']) }} – {{ $clockAt($line['shift_to']) }}" data-shift-band></span>
        @endif

        @foreach($line['segments'] as $segment)
            <span @class(['absolute shadow-sm', $segmentClass[$segment['kind']], 'inset-y-0 rounded-full' => ! $barLarge, 'inset-y-1 rounded-md' => $barLarge])
                  style="left: {{ $barPos($segment['from']) }}%; width: {{ max(0.8, $barPos($segment['to']) - $barPos($segment['from'])) }}%"
                  title="{{ $segmentLabel[$segment['kind']] }} {{ $clockAt($segment['from']) }}{{ $segment['kind'] === 'open' ? '' : ' – '.($segment['kind'] === 'live' ? 'now' : $clockAt($segment['to'])).' · '.$barDuration($segment['to'] - $segment['from']) }}"
                  data-segment="{{ $segment['kind'] }}"></span>
        @endforeach

        @foreach($line['marks'] as $mark)
            <span class="absolute top-1/2 z-10 -translate-x-1/2 -translate-y-1/2" style="left: {{ $barPos($mark['at']) }}%"
                  title="{{ $mark['title'] }}{{ $mark['resolved'] ? ' — corrected' : ' — not regularised' }}" data-mark>
                <span @class(['block rotate-45 rounded-[2px] border-2 border-white shadow dark:border-zinc-900', 'size-2.5' => ! $barLarge, 'size-3.5' => $barLarge, 'bg-violet-500' => $mark['resolved'], 'bg-rose-500' => ! $mark['resolved']])></span>
            </span>
        @endforeach
    </div>

    @if($barLarge)
        @foreach($line['ticks'] as $punch)
            <span @class(['absolute -translate-x-1/2', 'top-0' => $punch['dir'] === 'IN', 'bottom-0' => $punch['dir'] === 'OUT'])
                  style="left: {{ $barPos($punch['at']) }}%" title="{{ $punch['title'] }} · {{ $punch['time'] }}" data-punch-tick="{{ $punch['dir'] }}">
                <span @class(['block size-2 rounded-full ring-2 ring-white dark:ring-zinc-900', 'bg-emerald-500' => $punch['dir'] === 'IN', 'bg-orange-500' => $punch['dir'] === 'OUT'])></span>
            </span>
        @endforeach
    @endif
</div>
