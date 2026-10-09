@props([
    'items' => [],   // array<int, array{label: string, count: int|string, href?: string|null, tone?: string}>
])

@php
    $tone = fn (string $t, int $n): string => $n === 0
        ? 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400'
        : match ($t) {
            'red' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/15 dark:text-rose-400',
            'blue' => 'bg-blue-50 text-blue-700 dark:bg-blue-500/15 dark:text-blue-400',
            default => 'bg-amber-50 text-amber-700 dark:bg-amber-500/15 dark:text-amber-400',
        };
@endphp

{{-- One compact list for everything waiting on the reader, instead of a tall
     card per approval type. Rows with nothing waiting stay visible but quiet. --}}
<ul {{ $attributes->class('divide-y divide-zinc-100 dark:divide-white/5') }}>
    @foreach($items as $item)
        @php
            $count = is_numeric($item['count'] ?? 0) ? (int) $item['count'] : 0;
            $href = $item['href'] ?? null;
        @endphp
        <li>
            <{{ $href ? 'a' : 'div' }} @if($href) href="{{ $href }}" wire:navigate @endif
                class="flex items-center justify-between gap-3 py-2.5 text-[13px] {{ $href ? 'transition hover:text-brand-600' : '' }} {{ $count === 0 ? 'text-zinc-400 dark:text-zinc-500' : 'font-medium text-zinc-800 dark:text-zinc-100' }}">
                <span class="min-w-0 truncate">{{ $item['label'] }}</span>
                <span class="inline-flex min-w-[1.75rem] justify-center rounded-full px-2 py-0.5 text-[11px] font-bold tabular-nums {{ $tone($item['tone'] ?? 'amber', $count) }}">{{ $item['count'] }}</span>
            </{{ $href ? 'a' : 'div' }}>
        </li>
    @endforeach
</ul>
