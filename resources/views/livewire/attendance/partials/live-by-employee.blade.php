{{--
    By Employee: one person day by day over a range (AttendanceRegister::employee()),
    newest first, with totals and every punch.
--}}
@php $person = $register; @endphp

<div class="ui-surface flex flex-wrap items-end justify-between gap-3 p-3" data-by-employee>
    <div class="flex flex-wrap items-end gap-2">
        <label class="text-xs font-semibold text-zinc-500">Employee
            <select wire:model.live="employeeId" class="{{ $select }} mt-1 block min-w-[14rem]" aria-label="Employee">
                <option value="">Choose an employee…</option>
                @foreach($people as $p)<option value="{{ $p->id }}">{{ $p->user?->name }}{{ $p->employee_id ? ' · '.$p->employee_id : '' }}</option>@endforeach
            </select>
        </label>
        <label class="text-xs font-semibold text-zinc-500">From
            <input type="date" wire:model.live="from" max="{{ now()->toDateString() }}" class="{{ $dateInput }} mt-1 block">
        </label>
        <label class="text-xs font-semibold text-zinc-500">To
            <input type="date" wire:model.live="to" max="{{ now()->toDateString() }}" class="{{ $dateInput }} mt-1 block">
        </label>
    </div>
    <div class="flex flex-wrap gap-1">
        @foreach(\App\Livewire\Attendance\LiveAttendance::PRESETS as $key => $label)
            <button type="button" wire:click="preset('{{ $key }}')" class="rounded-lg px-2.5 py-1.5 text-xs font-semibold text-zinc-600 hover:bg-orange-50 hover:text-orange-600 dark:text-zinc-300 dark:hover:bg-orange-500/10">{{ $label }}</button>
        @endforeach
    </div>
</div>

@if($person === null)
    <div class="ui-surface px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
        {{ $employeeId ? 'This employee is outside the people you can see.' : 'Choose an employee to see their days, punch by punch.' }}
    </div>
@else
    @php
        $e = $person['employee'];
        $t = $person['totals'];
        $cards = [
            ['Present days', $t['present'], 'text-zinc-900 dark:text-white'],
            ['Late', $t['late'], $t['late'] ? 'text-amber-600' : 'text-zinc-900 dark:text-white'],
            ['Absent', $t['absent'], $t['absent'] ? 'text-red-600' : 'text-zinc-900 dark:text-white'],
            ['On leave', $t['leave'], 'text-zinc-900 dark:text-white'],
            ['Worked', $hm($t['worked_minutes']), 'text-zinc-900 dark:text-white'],
            ['Average a day', $hm($t['average_minutes']), 'text-zinc-900 dark:text-white'],
            ['Missed punches', $t['missed'], $t['missed'] ? 'text-red-600' : 'text-zinc-900 dark:text-white'],
            ['Missing check-outs', $t['missing_checkout'], $t['missing_checkout'] ? 'text-red-600' : 'text-zinc-900 dark:text-white'],
        ];
    @endphp
    <div class="ui-surface flex flex-wrap items-center justify-between gap-3 p-4">
        <div class="flex items-center gap-3">
            <span class="inline-flex size-11 items-center justify-center rounded-full bg-orange-100 text-sm font-bold text-orange-700 dark:bg-orange-500/20 dark:text-orange-300">{{ \App\Services\Attendance\LiveAttendanceService::initials((string) $e->user?->name) }}</span>
            <div>
                <div class="text-base font-bold text-zinc-900 dark:text-white" data-employee-name>{{ $e->user?->name }}</div>
                <div class="text-xs text-zinc-500">{{ $e->employee_id }} · {{ $e->department?->name ?? 'No department' }} · {{ $e->shift?->name ?? 'No shift' }}</div>
            </div>
        </div>
        <div class="text-sm font-semibold text-zinc-700 dark:text-zinc-200" data-range-label>
            {{ \Carbon\CarbonImmutable::parse($person['from'])->userDate() }} – {{ \Carbon\CarbonImmutable::parse($person['to'])->userDate() }}
            <span class="font-normal text-zinc-400">· {{ count($person['days']) }} {{ \Illuminate\Support\Str::plural('day', count($person['days'])) }}</span>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 xl:grid-cols-8" data-employee-totals>
        @foreach($cards as [$label, $value, $tone])
            <div class="ui-surface p-3">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                <div class="mt-1 text-xl font-black tabular-nums {{ $tone }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    <div class="ui-surface overflow-x-auto">
        <table class="w-full min-w-[1080px] text-sm" data-employee-days>
            <thead>
                <tr class="border-b border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <th class="px-4 py-2.5 font-medium">Date</th>
                    <th class="px-3 py-2.5 font-medium">Status</th>
                    <th class="px-3 py-2.5 font-medium">First in</th>
                    <th class="px-3 py-2.5 font-medium">Last out</th>
                    <th class="px-3 py-2.5 font-medium">Worked vs shift</th>
                    <th class="px-3 py-2.5 font-medium">Breaks</th>
                    <th class="px-4 py-2.5 font-medium">Punches</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($person['days'] as $l)
                    <tr wire:key="emp-day-{{ $l['date'] }}" data-employee-day="{{ $l['date'] }}" @class(['align-top text-zinc-700 dark:text-zinc-300', 'bg-zinc-50/60 dark:bg-white/[0.02]' => $l['key'] === 'off'])>
                        <td class="whitespace-nowrap px-4 py-3">
                            <button type="button" wire:click="openDay('{{ $l['date'] }}')" class="text-left" title="Everyone on this day">
                                <span class="block font-semibold text-zinc-900 hover:text-orange-600 dark:text-white">{{ \Carbon\CarbonImmutable::parse($l['date'])->userDate() }}</span>
                                <span class="block text-xs text-zinc-400">{{ $l['weekday'] }}</span>
                            </button>
                        </td>
                        <td class="px-3 py-3">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $chipTone[$l['tone']] }}">{{ $l['label'] }}</span>
                            @if($l['late'])<div class="mt-1 text-[11px] font-semibold text-amber-600">Late {{ $l['late_minutes'] }}m</div>@endif
                            @if($l['regularised'])<div class="mt-1 text-[11px] text-violet-600">Regularised</div>@endif
                        </td>
                        <td class="whitespace-nowrap px-3 py-3 tabular-nums">{{ $l['first_in'] ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-3 tabular-nums">{{ $l['last_out'] ?? ($l['missing_checkout'] ? 'Missing' : '—') }}</td>
                        <td class="w-48 px-3 py-3">
                            @if($l['present'])
                                @php $pct = $l['expected_minutes'] ? (int) floor($l['worked_minutes'] * 100 / $l['expected_minutes']) : 0; @endphp
                                <div class="flex items-center justify-between text-xs"><span>{{ $hm($l['worked_minutes']) }}</span><span class="font-bold {{ $progressText[$l['progress']] }}">{{ $pct }}%</span></div>
                                <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full {{ $progressBar[$l['progress']] }}" style="width: {{ min(100, $pct) }}%"></div></div>
                            @else
                                <span class="text-zinc-300 dark:text-zinc-600">—</span>
                            @endif
                        </td>
                        <td @class(['whitespace-nowrap px-3 py-3 tabular-nums', 'font-semibold text-red-600' => $l['excess_break']])>{{ $l['present'] ? $short($l['break_minutes']) : '—' }}</td>
                        <td class="px-4 py-3">@include('livewire.attendance.partials.live-trail', ['trail' => $l['trail']])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
