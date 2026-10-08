{{-- A punch timeline: one row per node from the PunchTimeline engine ($nodes). --}}
@php
    $nodeTone = fn (array $n): array => match (true) {
        $n['type'] === 'missing' => ['bg-amber-500', 'text-amber-700 dark:text-amber-400'],
        ($n['source'] ?? '') === 'regularisation' => ['bg-violet-500', 'text-violet-700 dark:text-violet-300'],
        $n['dir'] === 'IN' => ['bg-emerald-500', 'text-emerald-700 dark:text-emerald-400'],
        default => ['bg-rose-400', 'text-zinc-600 dark:text-zinc-300'],
    };
@endphp
<ol class="relative space-y-3 before:absolute before:bottom-2 before:left-[5px] before:top-2 before:w-px before:bg-zinc-200 dark:before:bg-zinc-700" data-punch-timeline>
    @foreach($nodes as $node)
        @php [$dot, $text] = $nodeTone($node); $isMissing = $node['type'] === 'missing'; @endphp
        <li class="relative flex items-baseline gap-4 pl-6" @if(($node['source'] ?? '') === 'regularisation') data-regularised @endif>
            <span class="absolute left-0 top-1.5 size-[11px] rounded-full ring-4 ring-white dark:ring-zinc-900 {{ $dot }} @if($node['type'] === 'live') animate-pulse @endif"></span>
            <span class="w-20 shrink-0 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $isMissing ? '—' : $node['time'] }}</span>
            <span class="text-sm {{ $text }}">
                @if($isMissing)
                    Missing {{ $node['dir'] }} — needs regularisation
                @else
                    {{ $node['method_label'] ?? (($node['source'] ?? '') === 'web' ? 'Web punch' : 'Punch') }} • {{ $node['dir'] }}
                    @if(($node['source'] ?? '') === 'regularisation')<span class="ml-1 rounded bg-violet-50 px-1.5 py-0.5 text-[10px] font-semibold text-violet-700 dark:bg-violet-500/10 dark:text-violet-300">Regularised</span>@endif
                    @if($node['type'] === 'live')<span class="ml-1 text-[11px] font-semibold text-emerald-600">working now</span>@endif
                @endif
            </span>
        </li>
    @endforeach
</ol>
