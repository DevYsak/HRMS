{{-- A compact "label · count" list for dashboards. Zero rows stay but are
     muted, so the list keeps its shape; a row links only when the viewer can
     open its page. --}}
@php $listRows = collect($rows); @endphp
<ul class="divide-y divide-zinc-100 dark:divide-white/5">
    @foreach($listRows as $row)
        @php
            $count = (int) $row['count'];
            $tone = $count > 0
                ? (($row['tone'] ?? null) === 'rose' ? 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' : 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300')
                : 'bg-zinc-100 text-zinc-400 dark:bg-white/5 dark:text-zinc-500';
        @endphp
        <li>
            @if(($row['href'] ?? null) && $count > 0)
                <a href="{{ $row['href'] }}" wire:navigate class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm hover:bg-zinc-50 dark:hover:bg-white/5">
                    <span class="text-zinc-700 dark:text-zinc-200">{{ $row['label'] }}</span>
                    <span class="inline-flex min-w-7 justify-center rounded-full px-2 py-0.5 text-xs font-bold tabular-nums {{ $tone }}">{{ $count }}</span>
                </a>
            @else
                <div class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                    <span class="{{ $count > 0 ? 'text-zinc-700 dark:text-zinc-200' : 'text-zinc-400 dark:text-zinc-500' }}">{{ $row['label'] }}</span>
                    <span class="inline-flex min-w-7 justify-center rounded-full px-2 py-0.5 text-xs font-bold tabular-nums {{ $tone }}">{{ $count }}</span>
                </div>
            @endif
        </li>
    @endforeach
</ul>
@if($listRows->sum('count') === 0)
    <p class="px-5 pb-4 pt-1 text-xs text-zinc-400">{{ $empty ?? 'Nothing to show.' }}</p>
@endif
