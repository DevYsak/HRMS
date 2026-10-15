{{--
    A readiness grid: rows ($gridRows: key => label) × columns ($gridCols: key => label),
    each cell a QaChecklistItem (category $gridCategory) or NOT TESTED. Managers click a
    cell to mark it. $cellKey(rowKey, colKey) gives the stored dimension.
--}}
@php
    $cellTone = [
        'pass' => 'bg-emerald-500 text-white',
        'partial' => 'bg-amber-400 text-amber-950',
        'fail' => 'bg-rose-500 text-white',
        'not_tested' => 'bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500',
    ];
    $cellWord = ['pass' => 'PASS', 'partial' => 'PART', 'fail' => 'FAIL', 'not_tested' => '—'];
@endphp

<div class="ui-surface overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-zinc-100 px-4 py-3 dark:border-white/5">
        <p class="text-sm text-zinc-600 dark:text-zinc-300">{{ $gridIntro }}</p>
        <div class="flex flex-wrap gap-1.5 text-[11px] font-semibold">
            @foreach(['pass' => 'PASS', 'partial' => 'PARTIAL', 'fail' => 'FAIL', 'not_tested' => 'NOT TESTED'] as $k => $l)
                <span class="rounded px-2 py-0.5 {{ $cellTone[$k] }}">{{ $l }}</span>
            @endforeach
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-xs" style="min-width: {{ 180 + count($gridCols) * 72 }}px">
            <thead class="bg-zinc-50 text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                <tr>
                    <th class="sticky left-0 bg-zinc-50 px-4 py-2 text-left dark:bg-zinc-900">{{ $gridCorner }}</th>
                    @foreach($gridCols as $colLabel)
                        <th class="px-1 py-2 text-center">{{ $colLabel }}</th>
                    @endforeach
                    <th class="px-3 py-2 text-center">Score</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($gridRows as $rowKey => $rowLabel)
                    @php $points = 0; @endphp
                    <tr>
                        <td class="sticky left-0 whitespace-nowrap bg-white px-4 py-2 font-medium text-zinc-800 dark:bg-zinc-900 dark:text-zinc-100">{{ $rowLabel }}</td>
                        @foreach($gridCols as $colKey => $colLabel)
                            @php
                                $dimension = $cellKey($rowKey, $colKey);
                                $item = $cells[$rowKey.'|'.$dimension] ?? null;
                                $state = $item?->status ?? 'not_tested';
                                $points += ['pass' => 1, 'partial' => 0.5][$state] ?? 0;
                            @endphp
                            <td class="px-1 py-1.5 text-center">
                                <button type="button" @if($canManage) wire:click="editCell('{{ $gridCategory }}', @js($rowKey), @js($dimension))" @else disabled @endif
                                    title="{{ $rowLabel }} · {{ $colLabel }}{{ $item?->note ? ' — '.$item->note : '' }}"
                                    data-grid-cell="{{ $rowKey }}|{{ $dimension }}" data-state="{{ $state }}"
                                    class="inline-flex h-7 w-full min-w-[3.25rem] items-center justify-center rounded-md text-[10px] font-bold {{ $cellTone[$state] }} {{ $canManage ? 'hover:ring-2 hover:ring-orange-500/40' : 'cursor-default' }}">
                                    {{ $cellWord[$state] }}
                                </button>
                            </td>
                        @endforeach
                        <td class="px-3 py-2 text-center font-semibold tabular-nums text-zinc-700 dark:text-zinc-200">{{ round($points / max(1, count($gridCols)) * 100) }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
