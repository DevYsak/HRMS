@props(['today'])

@php
    $progress = $today['progress'];
    $steps = [
        ['label' => 'Clock In', 'time' => $today['check_in'], 'icon' => 'arrow-right-end-on-rectangle', 'pending' => 'Not yet'],
        ['label' => 'Break Start', 'time' => $today['break_start'], 'icon' => 'pause', 'pending' => 'No break yet'],
        ['label' => 'Break End', 'time' => $today['break_end'], 'icon' => 'play', 'pending' => $today['on_break'] ? 'On break now' : 'No break yet'],
        ['label' => 'Clock Out', 'time' => $today['check_out'], 'icon' => 'arrow-left-start-on-rectangle', 'pending' => $today['working'] ? 'Still working' : ($today['missing_checkout'] ? 'Missing checkout' : 'Not yet')],
    ];
@endphp

{{-- TodayTimeline — the day's punches, then worked vs expected from
     ShiftProgress. No invented nine-hour day: without a shift the card says
     so instead of showing progress against nothing. --}}
<x-employee.dashboard.card title="Today's Timeline" icon="clock" :subtitle="now()->format('D, j M')" {{ $attributes }}>
    <ol class="relative space-y-3">
        @foreach($steps as $step)
            <li class="relative flex items-center gap-3">
                @if(! $loop->last)
                    <span aria-hidden="true" @class(['absolute left-[13px] top-7 h-[calc(100%-4px)] w-px', 'bg-orange-200 dark:bg-orange-500/30' => $step['time'], 'bg-zinc-200 dark:bg-white/10' => ! $step['time']])></span>
                @endif
                <span @class([
                    'relative z-[1] flex size-7 shrink-0 items-center justify-center rounded-full',
                    'bg-orange-500 text-white' => $step['time'],
                    'bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500' => ! $step['time'],
                ])>
                    <flux:icon :name="$step['icon']" class="size-3.5" />
                </span>
                <span class="flex min-w-0 flex-1 items-center justify-between gap-2">
                    <span class="text-sm text-zinc-700 dark:text-zinc-200">{{ $step['label'] }}</span>
                    <span @class(['shrink-0 text-sm tabular-nums', 'font-medium text-zinc-900 dark:text-white' => $step['time'], 'text-xs text-zinc-400' => ! $step['time']])>
                        {{ $step['time'] ?? $step['pending'] }}
                    </span>
                </span>
            </li>
        @endforeach
    </ol>

    <div class="pt-4">
        <div class="rounded-lg bg-zinc-50 p-3 dark:bg-white/[0.03]">
            @if($progress['measurable'])
                <div class="grid grid-cols-3 gap-2 text-center">
                    <div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Worked</p>
                        <p class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $progress['worked_label'] }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Expected</p>
                        <p class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $progress['expected_label'] }}</p>
                    </div>
                    <div>
                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400">Remaining</p>
                        <p class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $progress['remaining_label'] }}</p>
                    </div>
                </div>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-white/10" role="progressbar"
                     aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" aria-label="Worked against expected hours">
                    <div class="h-full rounded-full bg-orange-500" style="width: {{ $progress['percent'] }}%"></div>
                </div>
                <p class="mt-2 flex items-center justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
                    <span>{{ $progress['status_label'] }}</span>
                    @if($progress['overtime_minutes'] > 0)
                        <span class="text-amber-700 dark:text-amber-400">+{{ \App\Services\EmployeeDashboardService::formatMinutes($progress['overtime_minutes']) }} beyond shift</span>
                    @else
                        <span>{{ $progress['percent'] }}%</span>
                    @endif
                </p>
            @else
                <p class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                    <flux:icon.information-circle class="size-4 shrink-0 text-zinc-400" />
                    {{ $progress['status_label'] }}@if($today['worked_minutes'] > 0) · {{ \App\Services\EmployeeDashboardService::formatMinutes($today['worked_minutes']) }} worked @endif
                </p>
            @endif
        </div>
    </div>
</x-employee.dashboard.card>
