{{--
    One employee's complete punch log for the selected range — every day, every
    punch (IN green, OUT orange), sessions, breaks, hours and the audit flags.
    Shown when the Employee filter names one person (AllAttendance::employeePunchLog).
--}}
@php
    $tone = [
        'emerald' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300',
        'rose' => 'bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-300',
        'violet' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'zinc' => 'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300',
    ];
    $s = $log['summary'];
    $person = $log['employee'];
    $range = \Carbon\CarbonImmutable::parse($log['from'])->userDate().($log['from'] !== $log['to'] ? ' – '.\Carbon\CarbonImmutable::parse($log['to'])->userDate() : '');
@endphp

<section class="border-b border-orange-100/70 bg-orange-50/20 px-5 py-4 dark:border-white/10 dark:bg-white/[0.02]" data-punch-log="{{ $person->id }}">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h4 class="text-sm font-black text-zinc-900 dark:text-white">Punch log · {{ $person->user?->name ?? $person->employee_id }} <span class="font-semibold text-zinc-400">{{ $person->employee_id }}</span></h4>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $person->department?->name ?? 'No department' }} · {{ $range }}{{ $log['capped'] ? ' (the last '.\App\Livewire\Attendance\AllAttendance::PUNCH_LOG_MAX_DAYS.' days — narrow the dates for earlier ones)' : '' }}</p>
        </div>
        <button type="button" wire:click="$set('filterEmployee', '')" class="inline-flex items-center gap-1 rounded-lg bg-white px-2.5 py-1.5 text-xs font-bold text-zinc-500 shadow-sm transition hover:bg-zinc-50 dark:bg-zinc-900 dark:text-zinc-300"><flux:icon.x-mark class="size-3.5" /> All employees</button>
    </div>

    <dl class="mt-3 grid grid-cols-2 gap-2 sm:grid-cols-4 xl:grid-cols-8" data-punch-log-summary>
        @foreach([
            ['Present', $s['present'], 'emerald'], ['Late', $s['late'], 'amber'], ['Absent', $s['absent'], 'rose'], ['On leave', $s['leave'], 'violet'],
            ['Missed punches', $s['missed'], 'rose'], ['Multiple breaks', $s['multiple_breaks'], 'violet'], ['Punches', $s['punches'], 'zinc'], ['Hours', number_format($s['hours'], 1).'h', 'zinc'],
        ] as [$termLabel, $value, $t])
            <div class="rounded-xl bg-white px-3 py-2 shadow-sm dark:bg-zinc-900">
                <dt class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">{{ $termLabel }}</dt>
                <dd class="text-base font-black tabular-nums {{ in_array($termLabel, ['Missed punches', 'Absent'], true) && $value > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white' }}">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-3 overflow-x-auto rounded-xl bg-white shadow-sm dark:bg-zinc-900">
        <table class="w-full text-left text-xs" data-punch-log-days>
            <thead class="border-b border-zinc-100 text-[9px] font-bold uppercase tracking-widest text-zinc-400 dark:border-white/10">
                <tr>
                    <th class="px-3 py-2">Date</th><th class="py-2">Status</th><th class="py-2">Check In</th><th class="py-2">Check Out</th>
                    <th class="py-2">Every punch</th><th class="py-2">Breaks</th><th class="py-2">Hours</th><th class="px-3 py-2">Audit</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-50 dark:divide-white/5">
                @foreach($log['days'] as $day)
                    <tr wire:key="punch-log-{{ $day['date'] }}" data-punch-log-day="{{ $day['date'] }}" class="align-top">
                        <td class="whitespace-nowrap px-3 py-2">
                            <div class="font-bold text-zinc-800 dark:text-zinc-100">{{ \Carbon\CarbonImmutable::parse($day['date'])->userDate() }}</div>
                            <div class="text-[9px] text-zinc-400">{{ \Carbon\Carbon::parse($day['date'])->format('l') }}</div>
                        </td>
                        <td class="py-2"><span class="rounded-full px-2 py-0.5 text-[9px] font-bold {{ $tone[$day['tone']] }}">{{ $day['label'] }}</span></td>
                        <td class="py-2 font-mono font-bold tabular-nums text-zinc-800 dark:text-zinc-100">
                            {{ $day['check_in'] ?? '—' }}
                            @if($day['late_minutes'] > 0)<div class="font-sans text-[9px] font-bold text-amber-600">+{{ $day['late_minutes'] }}m late</div>@endif
                        </td>
                        <td class="py-2 font-mono font-bold tabular-nums text-zinc-800 dark:text-zinc-100">{{ $day['check_out'] ?? '—' }}</td>
                        <td class="py-2">
                            @if($day['punches'] === [])
                                <span class="text-zinc-400">—</span>
                            @else
                                <ol class="flex max-w-md flex-wrap gap-1">
                                    @foreach($day['punches'] as $punch)
                                        <li @class([
                                            'inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-mono text-[10px] font-bold tabular-nums',
                                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300' => $punch['direction'] === 'in',
                                            'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' => $punch['direction'] === 'out',
                                            'bg-zinc-100 text-zinc-600 dark:bg-white/10 dark:text-zinc-300' => ! in_array($punch['direction'], ['in', 'out'], true),
                                        ]) title="{{ $punch['source'] === 'regularisation' ? 'Regularised punch' : ($punch['source'] === 'system_auto' ? 'Automatic punch-out' : 'Recorded punch') }}">{{ strtoupper($punch['direction'] ?? '?') }} {{ $punch['time'] }}</li>
                                    @endforeach
                                </ol>
                            @endif
                        </td>
                        <td class="py-2 tabular-nums">
                            @if($day['punches'] !== [] || $day['break_minutes'] > 0)
                                <span class="font-semibold text-zinc-600 dark:text-zinc-300">{{ $day['break_minutes'] }}m</span>
                                <span class="block text-[9px] font-bold {{ $day['breaks'] >= 2 ? 'text-violet-600 dark:text-violet-300' : 'text-zinc-400' }}">{{ $day['breaks'] }} {{ \Illuminate\Support\Str::plural('break', $day['breaks']) }}</span>
                            @else
                                <span class="text-zinc-400">—</span>
                            @endif
                        </td>
                        <td class="py-2 font-black tabular-nums {{ $day['hours'] >= 8 ? 'text-emerald-600' : ($day['hours'] > 0 ? 'text-zinc-800 dark:text-zinc-100' : 'text-zinc-300') }}">{{ $day['hours'] > 0 ? number_format($day['hours'], 1).'h' : '—' }}</td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-1">
                                @if($day['missed'])<span class="rounded-full bg-rose-50 px-2 py-0.5 text-[9px] font-bold text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">Missed punch</span>@endif
                                @if($day['breaks'] >= 2)<span class="rounded-full bg-violet-50 px-2 py-0.5 text-[9px] font-bold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">Multiple breaks</span>@endif
                                @if($day['late_minutes'] > 0)<span class="rounded-full bg-amber-50 px-2 py-0.5 text-[9px] font-bold text-amber-700 dark:bg-amber-500/10 dark:text-amber-300">Late</span>@endif
                                @if($day['duplicates'] > 0)<span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[9px] font-bold text-zinc-500 dark:bg-white/10 dark:text-zinc-300">Duplicate scan</span>@endif
                                @if($day['regularised'])<span class="rounded-full bg-blue-50 px-2 py-0.5 text-[9px] font-bold text-blue-600 dark:bg-blue-500/10 dark:text-blue-300">Regularised</span>@endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
