{{-- Attendance History table over $rows (monthHistory rows, newest first). Rows with punches open the punch-detail modal. --}}
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
        @foreach($rows as $r)
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
            <tr @class(['text-zinc-700 dark:text-zinc-300', 'cursor-pointer hover:bg-zinc-50 dark:hover:bg-white/5' => $hasPunch, 'text-zinc-400' => ! $hasPunch])
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
