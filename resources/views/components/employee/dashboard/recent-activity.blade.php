@props(['activity'])

{{-- RecentActivity — the employee's own requests and records, newest first.
     Built from their records, never the audit log. --}}
<x-employee.dashboard.card title="Recent Activity" icon="clock" {{ $attributes }}>
    @if($activity->isEmpty())
        <x-employee.dashboard.empty-state icon="sparkles" title="No recent activity" text="Leave, WFH, overtime and attendance requests you make will show up here." />
    @else
        <ol class="-mx-2 space-y-0.5">
            @foreach($activity as $item)
                @php
                    $dot = match ($item['tone']) {
                        'success' => 'bg-emerald-500',
                        'danger' => 'bg-rose-500',
                        'warning' => 'bg-amber-500',
                        default => 'bg-zinc-300 dark:bg-zinc-600',
                    };
                    $tag = $item['url'] ? 'a' : 'div';
                @endphp
                <li>
                    <{{ $tag }} @if($item['url']) href="{{ $item['url'] }}" wire:navigate @endif
                        class="flex items-start gap-3 rounded-lg px-2 py-2 transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-orange-500 dark:hover:bg-white/[0.03]">
                        <span class="relative mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                            <flux:icon :name="$item['icon']" class="size-3.5" />
                            <span class="absolute -bottom-0.5 -right-0.5 size-2 rounded-full ring-2 ring-white dark:ring-ink-900 {{ $dot }}" aria-hidden="true"></span>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate text-sm text-zinc-800 dark:text-zinc-100">{{ $item['title'] }}</span>
                            @if($item['meta'])
                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $item['meta'] }}</span>
                            @endif
                        </span>
                        <span class="shrink-0 pt-0.5 text-[11px] text-zinc-400">{{ $item['time']->diffForHumans(short: true) }}</span>
                    </{{ $tag }}>
                </li>
            @endforeach
        </ol>
    @endif
</x-employee.dashboard.card>
