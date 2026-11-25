{{--
    By Day: everyone's day on one date (AttendanceRegister::day()) — status,
    first in, last out, worked against the shift, breaks, and every punch.
--}}
@php
    $day = $register;
    $dayDate = \Carbon\CarbonImmutable::parse($day['date']);
@endphp

<div class="ui-surface flex flex-wrap items-center justify-between gap-3 p-3" data-by-day>
    <div class="flex items-center gap-2">
        <button type="button" wire:click="shiftDate(-1)" class="inline-flex size-9 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" aria-label="Previous day"><flux:icon.chevron-left class="size-4" /></button>
        <input type="date" wire:model.live="date" max="{{ now()->toDateString() }}" class="{{ $dateInput }}" aria-label="Date">
        <button type="button" wire:click="shiftDate(1)" @disabled($dayDate->isToday()) class="inline-flex size-9 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" aria-label="Next day"><flux:icon.chevron-right class="size-4" /></button>
        @unless($dayDate->isToday())
            <button type="button" wire:click="openDay('{{ now()->toDateString() }}')" class="rounded-xl px-3 py-2 text-xs font-semibold text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-500/10">Today</button>
        @endunless
        <span class="text-sm font-semibold text-zinc-800 dark:text-zinc-100" data-day-label>{{ $dayDate->format('l') }}, {{ $dayDate->userDate() }}</span>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <div class="relative">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search people…" aria-label="Search people"
                class="w-48 rounded-xl border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
        </div>
        <select wire:model.live="department" class="{{ $select }}" aria-label="Department">
            <option value="">All departments</option>
            @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
    </div>
</div>

<div class="flex flex-wrap items-center gap-2" data-day-pills>
    <button type="button" wire:click="$set('dayStatus', '')" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 ring-inset',
        'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900' => $dayStatus === '',
        'bg-white text-zinc-700 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700' => $dayStatus !== ''])>Everyone {{ $day['counts']['all'] }}</button>
    @foreach(\App\Services\Attendance\AttendanceRegister::DAY_STATUSES as $key => $label)
        <button type="button" wire:click="setDayStatus('{{ $key }}')" data-day-pill="{{ $key }}" @class(['rounded-full px-3.5 py-1.5 text-xs font-semibold ring-1 ring-inset',
            'bg-zinc-900 text-white ring-zinc-900 dark:bg-white dark:text-zinc-900' => $dayStatus === $key,
            'bg-red-50 text-red-700 ring-red-300 dark:bg-red-500/10 dark:text-red-300' => $dayStatus !== $key && in_array($key, ['missed', 'missing', 'absent'], true) && $day['counts'][$key] > 0,
            'bg-white text-zinc-700 ring-zinc-200 dark:bg-zinc-900 dark:text-zinc-200 dark:ring-zinc-700' => $dayStatus !== $key && ! (in_array($key, ['missed', 'missing', 'absent'], true) && $day['counts'][$key] > 0)])>
            {{ $label }} <span class="opacity-70">{{ $day['counts'][$key] }}</span>
        </button>
    @endforeach
</div>

<div class="ui-surface overflow-x-auto">
    @if($day['rows'] === [])
        <p class="px-5 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">No employees match these filters.</p>
    @else
        <table class="w-full min-w-[1080px] text-sm" data-day-table>
            <thead>
                <tr class="border-b border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                    <th class="px-4 py-2.5 font-medium">Person</th>
                    <th class="px-3 py-2.5 font-medium">Status</th>
                    <th class="px-3 py-2.5 font-medium">First in</th>
                    <th class="px-3 py-2.5 font-medium">Last out</th>
                    <th class="px-3 py-2.5 font-medium">Worked vs shift</th>
                    <th class="px-3 py-2.5 font-medium">Breaks</th>
                    <th class="px-4 py-2.5 font-medium">Punches</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                @foreach($day['rows'] as $l)
                    <tr wire:key="day-{{ $l['employee_id'] }}" data-day-row="{{ $l['employee_id'] }}" class="align-top text-zinc-700 dark:text-zinc-300">
                        <td class="px-4 py-3">
                            <button type="button" wire:click="openEmployee({{ $l['employee_id'] }}, '{{ $l['date'] }}')" class="flex items-center gap-3 text-left">
                                <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-[11px] font-bold text-zinc-600 dark:bg-white/10 dark:text-zinc-200">{{ $l['initials'] }}</span>
                                <span>
                                    <span class="block font-semibold text-zinc-900 hover:text-orange-600 dark:text-white">{{ $l['name'] }}</span>
                                    <span class="block text-xs text-zinc-400">{{ $l['department'] }}</span>
                                </span>
                            </button>
                        </td>
                        <td class="px-3 py-3">
                            <span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $chipTone[$l['tone']] }}">{{ $l['label'] }}</span>
                            @if($l['late'])<div class="mt-1 whitespace-nowrap text-[11px] font-semibold text-amber-600">Late {{ $l['late_minutes'] }}m</div>@endif
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
    @endif
</div>
