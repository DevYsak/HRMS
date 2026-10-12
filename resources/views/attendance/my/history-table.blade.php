{{-- Attendance History table over $rows (one page of the filtered monthHistory rows). Rows with punches open the punch-detail modal. --}}
@php
    $sortButton = function (string $column, string $text) {
        $active = $this->historySort === $column;
        $arrow = $active ? ($this->historyDir === 'desc' ? '↓' : '↑') : '';

        return '<button type="button" wire:click="sortHistory(\''.$column.'\')" class="inline-flex items-center gap-1 font-semibold uppercase tracking-wide hover:text-orange-600 dark:hover:text-orange-300'.($active ? ' text-zinc-800 dark:text-slate-100' : '').'" data-sort="'.$column.'">'
            .e($text).($arrow ? '<span aria-hidden="true">'.$arrow.'</span>' : '').'</button>';
    };
    $ariaSort = fn (string $column) => $this->historySort === $column ? ($this->historyDir === 'desc' ? 'descending' : 'ascending') : 'none';
    $modeIcon = ['office' => 'building-office-2', 'wfh' => 'home', 'hybrid' => 'arrows-right-left'];
    $th = 'px-3 py-2.5 text-[11px] font-semibold uppercase tracking-wide';
@endphp
<table class="w-full min-w-[920px] text-sm" data-history-table>
    <thead>
        <tr class="border-y border-zinc-100 bg-zinc-50/70 text-left text-zinc-500 dark:border-white/[0.06] dark:bg-white/[0.02] dark:text-slate-400">
            <th class="{{ $th }} pl-5" aria-sort="{{ $ariaSort('date') }}">{!! $sortButton('date', 'Date') !!}</th>
            <th class="{{ $th }}">Day</th>
            <th class="{{ $th }}" aria-sort="{{ $ariaSort('status') }}">{!! $sortButton('status', 'Status') !!}</th>
            <th class="{{ $th }}">First In</th>
            <th class="{{ $th }}">Last Out</th>
            <th class="{{ $th }} text-right" aria-sort="{{ $ariaSort('worked') }}">{!! $sortButton('worked', 'Worked') !!}</th>
            <th class="{{ $th }} text-right">Break</th>
            <th class="{{ $th }}">Exception</th>
            <th class="{{ $th }}">Mode</th>
            <th class="{{ $th }} pr-5 text-right">Actions</th>
        </tr>
    </thead>
    <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.05]">
        @foreach($rows as $r)
            @php
                $hasPunch = $r['check_in'] !== null;
                $isToday = $r['date'] === $todayKey;
                $exceptions = $this->historyExceptions($r, $isToday && $isLive);
                $mode = $r['mode'] ? \App\Enums\AttendanceMode::tryFromValue($r['mode']) : null;
            @endphp
            <tr @class(['text-zinc-700 dark:text-slate-300', 'cursor-pointer transition hover:bg-orange-50/40 dark:hover:bg-white/[0.03]' => $hasPunch, 'text-zinc-400 dark:text-slate-500' => ! $hasPunch])
                @if($hasPunch) wire:click="showPunchDetail('{{ $r['date'] }}')" @endif
                wire:key="history-row-{{ $r['date'] }}"
                data-history-row="{{ $r['date'] }}">
                <td class="whitespace-nowrap py-3 pl-5 pr-3 font-semibold text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($r['date'])->format('D d M') }}@if($isToday)<span class="ml-1.5 rounded bg-orange-100 px-1 py-px text-[10px] font-bold text-orange-700 dark:bg-orange-500/15 dark:text-orange-300">TODAY</span>@endif</td>
                <td class="px-3 py-3 text-zinc-500 dark:text-slate-400">{{ \Carbon\Carbon::parse($r['date'])->format('D') }}</td>
                <td class="px-3 py-3">
                    <span class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $tone[$r['tone']] ?? $tone['muted'] }}" data-history-status>
                        <span class="size-1.5 rounded-full bg-current opacity-70" aria-hidden="true"></span>{{ $r['status'] }}
                    </span>
                </td>
                <td class="px-3 py-3 tabular-nums">{{ $r['check_in'] ?? '—' }}</td>
                <td class="px-3 py-3 tabular-nums">{{ $r['check_out'] ?? ($isToday && $isLive ? 'Working' : '—') }}</td>
                <td class="px-3 py-3 text-right font-medium tabular-nums">{{ $hasPunch ? $hm($r['worked_minutes']) : '—' }}</td>
                <td class="px-3 py-3 text-right tabular-nums">{{ $hasPunch ? $hm($r['break_minutes']) : '—' }}</td>
                <td class="px-3 py-3">
                    @forelse($exceptions as $ex)
                        <span @class([
                            'mr-1 inline-block whitespace-nowrap rounded-md px-1.5 py-0.5 text-[11px] font-semibold',
                            'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300' => str_starts_with($ex, 'Missing') || str_starts_with($ex, 'Late') || $ex === 'Auto Checkout',
                            'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300' => str_starts_with($ex, 'Regularis'),
                            'bg-zinc-100 text-zinc-600 dark:bg-white/[0.05] dark:text-slate-300' => ! str_starts_with($ex, 'Missing') && ! str_starts_with($ex, 'Late') && ! str_starts_with($ex, 'Regularis') && $ex !== 'Auto Checkout',
                        ])>{{ $ex }}</span>
                    @empty
                        <span class="text-zinc-300 dark:text-slate-600">—</span>
                    @endforelse
                </td>
                <td class="px-3 py-3">
                    @if($mode)
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-xs font-medium text-zinc-600 dark:text-slate-300">
                            <flux:icon :name="$modeIcon[$mode->value] ?? 'map-pin'" variant="micro" class="size-3.5 text-zinc-400 dark:text-slate-500" />{{ $mode->label() }}
                        </span>
                    @else
                        <span class="text-zinc-300 dark:text-slate-600">—</span>
                    @endif
                </td>
                <td class="py-3 pl-3 pr-5 text-right">
                    @if($hasPunch || $r['status'] === 'Absent')
                        <button type="button" wire:click.stop="showScoreDecision('{{ $r['date'] }}')" class="rounded-md px-1.5 py-0.5 text-xs font-semibold text-orange-600 underline decoration-orange-300 underline-offset-2 hover:text-orange-700 dark:text-orange-400" title="How this day's status was decided">Why?</button>
                    @else
                        <span class="text-zinc-300 dark:text-slate-600">—</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </tbody>
</table>
