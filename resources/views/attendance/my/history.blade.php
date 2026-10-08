{{--
    Attendance History — the selected month, newest first, from monthHistory.
    Upcoming dates are left out; the latest 14 days show first. A day with
    punches opens its detail drawer (punch-detail); "Why?" explains the
    engine's decision for that day.
--}}
@php
    $historyRows = collect($mh['rows'] ?? [])
        // Future dates are left out whatever their type (a future weekly off or holiday too).
        ->reject(fn (array $r) => $r['date'] > today()->toDateString() || $r['status'] === 'Not employed')
        ->reverse()->values();
    $tone = [
        'green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'red' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        'blue' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'violet' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'rose' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'muted' => 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400',
    ];
    $todayKey = today()->toDateString();
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="history-title" data-history x-data="{ all: false }">
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 pb-2 pt-4">
        <h2 id="history-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Attendance History</h2>
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $mh['label'] }} · click a day for its punches</span>
    </div>

    @if($historyRows->isEmpty())
        <p class="px-5 pb-6 pt-2 text-sm text-zinc-500 dark:text-zinc-400">No attendance days in {{ $mh['label'] }} yet.</p>
    @else
        <div class="relative overflow-x-auto">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-y border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                        <th class="px-5 py-2 font-medium">Date</th>
                        <th class="px-3 py-2 font-medium">Status</th>
                        <th class="px-3 py-2 font-medium">First In</th>
                        <th class="px-3 py-2 font-medium">Last Out</th>
                        <th class="px-3 py-2 text-right font-medium">Worked</th>
                        <th class="px-3 py-2 text-right font-medium">Break</th>
                        <th class="px-3 py-2 font-medium">Exception</th>
                        <th class="px-3 py-2"><span class="sr-only">Why</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($historyRows as $i => $r)
                        @php
                            $hasPunch = $r['check_in'] !== null;
                            $isToday = $r['date'] === $todayKey;
                            $exceptions = array_filter([
                                $r['missing_checkout'] && ! ($isToday && $isLive) ? 'Missing OUT' : null,
                                $r['regularised'] ? 'Regularised' : null,
                                $r['regularisation'] && ! $r['regularised'] ? 'Regularisation '.$r['regularisation'] : null,
                                $r['status'] === 'Late' && $r['late_minutes'] > 0 ? 'Late '.$r['late_minutes'].'m' : null,
                                $r['ot_hours'] > 0 ? 'OT '.$r['ot_hours'].'h' : null,
                                $r['holiday'],
                            ]);
                        @endphp
                        <tr @if($i >= 14) x-show="all" x-cloak @endif
                            @class(['text-zinc-700 dark:text-zinc-300', 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5' => $hasPunch, 'text-zinc-400' => ! $hasPunch])
                            @if($hasPunch) wire:click="showPunchDetail('{{ $r['date'] }}')" @endif
                            data-history-row="{{ $r['date'] }}">
                            <td class="whitespace-nowrap px-5 py-2.5 font-medium text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($r['date'])->format('D d M') }}</td>
                            <td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $tone[$r['tone']] ?? $tone['muted'] }}">{{ $r['status'] }}</span></td>
                            <td class="px-3 py-2.5 tabular-nums">{{ $r['check_in'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 tabular-nums">{{ $r['check_out'] ?? ($isToday && $isLive ? 'Working' : '—') }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $hasPunch ? $hm($r['worked_minutes']) : '—' }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ $hasPunch ? $hm($r['break_minutes']) : '—' }}</td>
                            <td class="px-3 py-2.5">
                                @forelse($exceptions as $ex)
                                    <span @class([
                                        'mr-1 whitespace-nowrap rounded px-1.5 py-0.5 text-[11px] font-semibold',
                                        'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400' => str_starts_with($ex, 'Missing') || str_starts_with($ex, 'Late'),
                                        'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' => str_starts_with($ex, 'Regularis'),
                                        'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300' => ! str_starts_with($ex, 'Missing') && ! str_starts_with($ex, 'Late') && ! str_starts_with($ex, 'Regularis'),
                                    ])>{{ $ex }}</span>
                                @empty
                                    <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                @endforelse
                            </td>
                            <td class="px-3 py-2.5 text-right">
                                @if($hasPunch || $r['status'] === 'Absent')
                                    <button type="button" wire:click.stop="showScoreDecision('{{ $r['date'] }}')" class="text-[11px] font-semibold text-zinc-400 hover:text-orange-600" title="How this day's status was decided">Why?</button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @if($historyRows->count() > 14)
            <div class="border-t border-zinc-100 px-5 py-2.5 dark:border-zinc-800">
                <button type="button" @click="all = ! all" class="text-xs font-semibold text-orange-600 hover:text-orange-700" x-text="all ? 'Show latest 14 days' : 'Show all {{ $historyRows->count() }} days'">Show all {{ $historyRows->count() }} days</button>
            </div>
        @endif
    @endif
</section>
