{{--
    The Live board: department chips, status filters, then Working / Out now /
    Away. Uses the shell's helpers ($hm, $short, $deptDot, $progressBar,
    $progressText, $chipTone) and LiveAttendanceService::snapshot().
--}}
@php
    $statusLine = function (array $r): array {
        return match (true) {
            $r['bucket'] === 'working' => $r['late'] ? ['Late '.$r['late_minutes'].'m', 'text-amber-600 dark:text-amber-400', 'bg-amber-500'] : ['Working', 'text-emerald-600 dark:text-emerald-400', 'bg-emerald-500'],
            $r['state'] === 'missing_checkout' => ['Missing checkout', 'text-red-600 dark:text-red-400', 'bg-red-500'],
            $r['state'] === 'on_break' => ['On break', 'text-sky-600 dark:text-sky-400', 'bg-sky-500'],
            $r['bucket'] === 'out' => ['Out', 'text-red-600 dark:text-red-400', 'bg-red-500'],
            $r['auto_checkout'] => ['Auto checkout', 'text-amber-600 dark:text-amber-400', 'bg-amber-400'],
            $r['state'] === 'completed' => ['Checked out', 'text-zinc-500 dark:text-zinc-400', 'bg-zinc-400'],
            $r['reason'] === 'leave' => ['On leave', 'text-violet-600 dark:text-violet-300', 'bg-violet-400'],
            $r['reason'] === 'absent' => ['Absent', 'text-red-600 dark:text-red-400', 'bg-red-400'],
            in_array($r['reason'], ['weekly_off', 'holiday', 'mdl'], true) => ['Day off', 'text-zinc-500 dark:text-zinc-400', 'bg-zinc-300'],
            default => ['Not in yet', 'text-zinc-500 dark:text-zinc-400', 'bg-zinc-300'],
        };
    };
    $sessionText = function (?int $since) use ($now_ms): string {
        if ($since === null) {
            return '';
        }
        $s = max(0, intdiv($now_ms - $since, 1000));
        $h = intdiv($s, 3600);
        $m = intdiv($s % 3600, 60);

        return ($h ? $h.':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT) : (string) $m).':'.str_pad((string) ($s % 60), 2, '0', STR_PAD_LEFT);
    };
    $sectionMeta = [
        'working' => ['Working', 'Punched in now', 'border-l-emerald-500'],
        'out' => ['Out now', 'Stepped out, on a break, or a check-out missing', 'border-l-red-500'],
        'away' => ['Away', 'Not in yet, on leave, a day off, or checked out', 'border-l-zinc-300 dark:border-l-zinc-600'],
    ];
    $leftBar = ['on_track' => 'border-l-emerald-500', 'nearing' => 'border-l-lime-500', 'done' => 'border-l-yellow-400', 'beyond' => 'border-l-orange-500', 'way_beyond' => 'border-l-rose-500'];
    $empty = $sections['working'] === [] && $sections['out'] === [] && $sections['away'] === [];
@endphp

{{-- Department chips --}}
<div class="ui-surface overflow-x-auto px-3" data-department-chips>
    <div class="flex min-w-max items-center gap-1">
        <button type="button" wire:click="setDepartment" @class([
            'inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-semibold',
            'border-orange-500 text-zinc-900 dark:text-white' => $department === '',
            'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400' => $department !== '',
        ]) data-department-chip="all">
            Everyone <span class="text-xs font-medium text-zinc-400">{{ $everyone['working'] }}/{{ $everyone['total'] }}</span>
        </button>
        @foreach($department_counts as $d)
            <button type="button" wire:click="setDepartment({{ $d['id'] }})" @class([
                'inline-flex items-center gap-2 border-b-2 px-3 py-3 text-sm font-semibold',
                'border-orange-500 text-zinc-900 dark:text-white' => (string) $d['id'] === $department,
                'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400' => (string) $d['id'] !== $department,
            ]) data-department-chip="{{ $d['id'] }}" title="{{ $d['working'] }} working of {{ $d['total'] }}">
                <span class="size-2 rounded-full {{ $deptDot($d['id']) }}"></span>{{ $d['name'] }}
                <span class="text-xs font-medium text-zinc-400">{{ $d['working'] }}</span>
            </button>
        @endforeach
    </div>
</div>

{{-- Status filters --}}
<div class="flex flex-wrap items-center gap-2" data-status-pills>
    <button type="button" wire:click="$set('status', '')" @class([
        'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 ring-inset transition',
        'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' => $status === '',
        'bg-white text-zinc-700 ring-zinc-200 hover:ring-zinc-300 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700' => $status !== '',
    ])>All {{ $pills['all'] }}</button>
    @foreach($statusOptions as $key => $label)
        @php $alarm = $key === 'out_too_long' && $pills[$key] > 0; @endphp
        <button type="button" wire:click="setStatus('{{ $key }}')" data-pill="{{ $key }}" @class([
            'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 ring-inset transition',
            'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900 dark:ring-white' => $status === $key,
            'bg-red-50 text-red-700 ring-red-300 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/40' => $status !== $key && $alarm,
            'bg-white text-zinc-700 ring-zinc-200 hover:ring-zinc-300 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700' => $status !== $key && ! $alarm,
        ])>
            @if(! $alarm && $status !== $key)
                <span @class(['size-1.5 rounded-full', 'bg-emerald-500' => $key === 'working', 'bg-red-500' => in_array($key, ['out', 'missing'], true),
                    'bg-amber-500' => $key === 'late', 'bg-violet-400' => $key === 'on_leave', 'bg-zinc-300' => in_array($key, ['not_started', 'checked_out', 'out_too_long'], true)])></span>
            @endif
            {{ $label }} <span class="opacity-70">{{ $pills[$key] }}</span>
        </button>
    @endforeach
</div>

@if($empty)
    <div class="ui-surface px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">No employees match these filters.</div>
@endif

@foreach($sectionMeta as $bucket => [$title, $hint, $bar])
    @continue($sections[$bucket] === [])
    <section class="space-y-2" data-board-section="{{ $bucket }}">
        <div class="flex flex-wrap items-center justify-between gap-2 px-1">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">
                {{ $title }} <span class="font-semibold text-zinc-400">{{ count($sections[$bucket]) }}</span>
                <span class="text-xs font-normal text-zinc-400">· {{ $hint }}</span>
            </h2>
            @if($bucket === 'working')
                <div class="flex flex-wrap items-center gap-3 text-[11px] text-zinc-500 dark:text-zinc-400" data-progress-legend>
                    @foreach(\App\Services\Attendance\LiveAttendanceService::PROGRESS as $tone => $label)
                        <span class="inline-flex items-center gap-1.5"><span class="h-1.5 w-3 rounded-full {{ $progressBar[$tone] }}"></span>{{ $label }}</span>
                    @endforeach
                </div>
            @elseif($bucket === 'away')
                <button type="button" wire:click="$toggle('showAway')" class="text-xs font-semibold text-orange-600 hover:text-orange-700">{{ $showAway ? 'Hide' : 'Show' }}</button>
            @endif
        </div>

        @if($bucket !== 'away' || $showAway)
            @if($layout === 'grid')
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                    @foreach($sections[$bucket] as $r)
                        @php [$line, $lineTone, $dot] = $statusLine($r); @endphp
                        <article wire:key="card-{{ $r['id'] }}" data-live-row="{{ $r['id'] }}" @class(['ui-surface border-l-4 p-4', $bucket === 'working' ? $leftBar[$r['progress']] : $bar])>
                            <div class="flex items-start justify-between gap-3">
                                <button type="button" wire:click="openEmployee({{ $r['id'] }})" class="flex min-w-0 items-center gap-3 text-left">
                                    <span class="relative inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-bold text-zinc-600 dark:bg-white/10 dark:text-zinc-200">
                                        {{ $r['initials'] }}<span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-white dark:ring-zinc-900 {{ $dot }}"></span>
                                    </span>
                                    <span class="min-w-0">
                                        <span class="block truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $r['name'] }}</span>
                                        <span class="block text-xs font-medium {{ $lineTone }}">{{ $line }}</span>
                                    </span>
                                </button>
                                @if($r['session_since_ms'])
                                    <span class="text-base font-bold tabular-nums text-zinc-900 dark:text-white" x-text="clock({{ $r['session_since_ms'] }})">{{ $sessionText($r['session_since_ms']) }}</span>
                                @endif
                            </div>
                            <p @class(['mt-3 text-xs', 'text-red-600 dark:text-red-400' => $bucket === 'out', 'text-zinc-600 dark:text-zinc-300' => $bucket !== 'out'])>{{ $r['note'] }}</p>
                            <p class="mt-1 flex items-center gap-1.5 text-[11px] text-zinc-400"><span class="size-1.5 rounded-full {{ $deptDot($r['department_id']) }}"></span>{{ $r['department'] }} · {{ $r['shift_name'] }}@if($r['mode']) · {{ str_replace('_', ' ', ucfirst($r['mode'])) }}@endif</p>
                            @if($r['state'] === 'missing_checkout')
                                <p class="mt-3 text-[11px] font-medium text-zinc-400">Not counted until the check-out is regularised</p>
                            @elseif($r['first_in'])
                                <div class="mt-3">
                                    <div class="flex items-center justify-between text-[11px]">
                                        <span class="text-zinc-500">{{ $hm($r['worked_minutes']) }} / {{ $hm($r['expected_minutes']) }}</span>
                                        <span class="font-bold {{ $progressText[$r['progress']] }}">{{ $r['progress_pct'] }}%</span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full {{ $progressBar[$r['progress']] }}" style="width: {{ min(100, $r['progress_pct']) }}%"></div></div>
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            @else
                <div class="ui-surface overflow-x-auto">
                    <table class="w-full min-w-[980px] text-sm" data-live-status>
                        <thead>
                            <tr class="border-b border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                <th class="px-4 py-2.5 font-medium">Person</th>
                                <th class="px-3 py-2.5 font-medium">On</th>
                                <th class="px-3 py-2.5 text-right font-medium">Session</th>
                                <th class="px-3 py-2.5 font-medium">Worked vs shift</th>
                                <th class="px-3 py-2.5 font-medium">Shift ends</th>
                                <th class="px-4 py-2.5 text-right font-medium">Today</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @php $lastGroup = null; @endphp
                            @foreach($sections[$bucket] as $r)
                                @php [$line, $lineTone, $dot] = $statusLine($r); @endphp
                                @if($group !== 'none' && $r['group'] !== $lastGroup)
                                    @php $lastGroup = $r['group']; @endphp
                                    <tr wire:key="group-{{ $bucket }}-{{ md5($r['group']) }}" class="bg-zinc-50/70 dark:bg-white/[0.02]">
                                        <td colspan="6" class="px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ $r['group'] }}</td>
                                    </tr>
                                @endif
                                <tr wire:key="row-{{ $r['id'] }}" data-live-row="{{ $r['id'] }}" @class(['border-l-4 text-zinc-700 dark:text-zinc-300', $bucket === 'working' ? $leftBar[$r['progress']] : $bar])>
                                    <td class="px-4 py-3">
                                        <button type="button" wire:click="openEmployee({{ $r['id'] }})" class="flex items-center gap-3 text-left" title="See {{ $r['name'] }}'s days">
                                            <span class="relative inline-flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-xs font-bold text-zinc-600 dark:bg-white/10 dark:text-zinc-200">
                                                {{ $r['initials'] }}<span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full ring-2 ring-white dark:ring-zinc-900 {{ $dot }}"></span>
                                            </span>
                                            <span>
                                                <span class="block font-semibold text-zinc-900 hover:text-orange-600 dark:text-white">{{ $r['name'] }}</span>
                                                <span class="block text-xs font-medium {{ $lineTone }}">{{ $line }}</span>
                                            </span>
                                        </button>
                                    </td>
                                    <td class="px-3 py-3">
                                        <div @class(['text-sm', 'font-medium text-red-600 dark:text-red-400' => $bucket === 'out', 'text-zinc-800 dark:text-zinc-100' => $bucket === 'working', 'text-zinc-500 dark:text-zinc-400' => $bucket === 'away'])>{{ $r['note'] }}</div>
                                        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
                                            <span class="size-1.5 rounded-full {{ $deptDot($r['department_id']) }}"></span>{{ $r['department'] }} · {{ $r['shift_name'] }}@if($r['mode']) · {{ str_replace('_', ' ', ucfirst($r['mode'])) }}@endif
                                        </div>
                                    </td>
                                    <td class="px-3 py-3 text-right">
                                        @if($r['session_since_ms'])
                                            <span class="text-base font-bold tabular-nums text-zinc-900 dark:text-white" x-text="clock({{ $r['session_since_ms'] }})" data-session>{{ $sessionText($r['session_since_ms']) }}</span>
                                        @endif
                                    </td>
                                    <td class="w-56 px-3 py-3">
                                        @if($r['state'] === 'missing_checkout')
                                            <span class="text-xs text-zinc-400">Not counted until the check-out is regularised</span>
                                        @elseif($r['first_in'])
                                            <div class="flex items-center justify-between text-xs">
                                                <span class="text-zinc-600 dark:text-zinc-300">{{ $hm($r['worked_minutes']) }} / {{ $hm($r['expected_minutes']) }}</span>
                                                <span class="font-bold {{ $progressText[$r['progress']] }}" data-progress="{{ $r['progress'] }}">{{ $r['progress_pct'] }}%</span>
                                            </div>
                                            <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full {{ $progressBar[$r['progress']] }}" style="width: {{ min(100, $r['progress_pct']) }}%"></div></div>
                                        @endif
                                    </td>
                                    <td class="px-3 py-3">
                                        @if($r['state'] === 'missing_checkout')
                                            <span class="whitespace-nowrap rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-500/10 dark:text-red-300">Overdue · {{ $r['shift_end'] }}</span>
                                        @elseif($bucket !== 'away' && $r['shift_end'])
                                            <span class="whitespace-nowrap rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-semibold text-zinc-600 dark:bg-white/5 dark:text-zinc-300">Ends {{ $r['shift_end'] }}</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm tabular-nums text-zinc-500 dark:text-zinc-400">{{ $short($r['worked_minutes']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif
    </section>
@endforeach
