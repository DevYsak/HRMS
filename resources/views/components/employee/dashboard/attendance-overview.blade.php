@props(['attendance'])

@php
    // One swatch per day state — the legend, the counts and the strip share it.
    $swatch = [
        'present' => 'bg-emerald-500',
        'late' => 'bg-amber-400',
        'half_day' => 'bg-amber-200 dark:bg-amber-300/70',
        'wfh' => 'bg-sky-400',
        'leave' => 'bg-violet-400',
        'holiday' => 'bg-orange-300',
        'weekly_off' => 'bg-zinc-200 dark:bg-white/15',
        'mdl' => 'bg-indigo-300 dark:bg-indigo-400/60',
        'absent' => 'bg-rose-400',
        'today' => 'bg-white ring-2 ring-inset ring-orange-400 dark:bg-transparent',
        'future' => 'bg-zinc-100 dark:bg-white/[0.04]',
    ];

    $c = $attendance['counts'];
    $stats = [
        ['Present', $c['present'], 'present'],
        ['Absent', $c['absent'], 'absent'],
        ['WFH', $c['wfh'], 'wfh'],
        ['Leave', $c['leave'], 'leave'],
        ['Late', $c['late'], 'late'],
        ['Half days', $c['half_day'], 'half_day'],
        ['Holidays', $c['holiday'], 'holiday'],
        ['Weekly offs', $c['weekly_off'], 'weekly_off'],
    ];
    if (($c['mdl'] ?? 0) > 0) {
        $stats[] = ['MDL shutdown', $c['mdl'], 'mdl'];
    }
    $days = collect($attendance['days']);
@endphp

{{-- AttendanceOverview — month-to-date counts plus a day strip built from the
     employee's real attendance, approved leave and holiday calendar. --}}
<x-employee.dashboard.card title="Attendance Overview" icon="chart-bar" :subtitle="$attendance['month_label']"
    :href="route('attendance.my')" cta="View attendance" {{ $attributes }}>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <p class="text-3xl font-semibold tabular-nums tracking-tight text-zinc-900 dark:text-white">{{ $attendance['percent'] }}%</p>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                Present {{ $attendance['present_days'] }} of {{ $attendance['working_days'] }} working {{ \Illuminate\Support\Str::plural('day', $attendance['working_days']) }} · {{ $attendance['range_label'] }}
            </p>
        </div>
        @if($attendance['present_days'] === 0)
            <p class="text-xs text-zinc-500 dark:text-zinc-400">No attendance recorded this month yet.</p>
        @endif
    </div>

    <dl class="mt-5 grid grid-cols-4 gap-x-3 gap-y-4 sm:grid-cols-8">
        @foreach($stats as [$label, $count, $state])
            <div class="min-w-0">
                <dt class="flex items-center gap-1.5 truncate text-[11px] text-zinc-500 dark:text-zinc-400">
                    <span class="size-2 shrink-0 rounded-[3px] {{ $swatch[$state] }}" aria-hidden="true"></span>{{ $label }}
                </dt>
                <dd class="mt-0.5 text-lg font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $count }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-5">
        <ol class="flex gap-[3px]" aria-label="Attendance day by day">
            @foreach($days as $day)
                <li class="h-7 min-w-0 flex-1 rounded-[4px] {{ $swatch[$day['state']] ?? $swatch['future'] }} @if($day['planned']) opacity-60 @endif"
                    title="{{ $day['title'] }}">
                    <span class="sr-only">{{ $day['title'] }}</span>
                </li>
            @endforeach
        </ol>
        <div class="mt-1.5 hidden gap-[3px] lg:flex" aria-hidden="true">
            @foreach($days as $day)
                <span @class(['min-w-0 flex-1 text-center text-[9px] tabular-nums', 'font-semibold text-orange-600 dark:text-orange-400' => $day['is_today'], 'text-zinc-400' => ! $day['is_today']])>{{ $day['day'] }}</span>
            @endforeach
        </div>
        <div class="mt-1.5 flex justify-between text-[10px] text-zinc-400 lg:hidden" aria-hidden="true">
            <span>{{ $days->first()['day'] ?? '' }} {{ \Illuminate\Support\Str::substr($attendance['month_label'], 0, 3) }}</span>
            <span>{{ $days->last()['day'] ?? '' }} {{ \Illuminate\Support\Str::substr($attendance['month_label'], 0, 3) }}</span>
        </div>
    </div>

    @if($attendance['avg_check_in'] || $attendance['avg_worked'])
        <dl class="grid grid-cols-3 gap-2 pt-4">
            @foreach([
                ['Avg. clock-in', $attendance['avg_check_in']],
                ['Avg. worked', $attendance['avg_worked']],
                ['On time', $attendance['on_time_rate'] !== null ? $attendance['on_time_rate'].'%' : null],
            ] as [$label, $value])
                <div class="min-w-0 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/[0.03]">
                    <dt class="truncate text-[11px] text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="mt-0.5 truncate text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value ?? 'No data yet' }}</dd>
                </div>
            @endforeach
        </dl>
    @endif
</x-employee.dashboard.card>
