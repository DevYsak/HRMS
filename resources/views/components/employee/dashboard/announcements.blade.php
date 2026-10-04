@props(['announcements'])

@php
    $holidays = $announcements['holidays'];
    $updates = $announcements['updates'];
@endphp

{{-- Announcements — upcoming holidays on the employee's own calendar, then
     their notifications with HR balance postings folded into one event. --}}
<x-employee.dashboard.card title="Announcements" icon="megaphone" :href="\Illuminate\Support\Facades\Route::has('notifications.index') ? route('notifications.index') : null" cta="Inbox" {{ $attributes }}>
    @if($holidays->isEmpty() && $updates->isEmpty())
        <x-employee.dashboard.empty-state icon="check-circle" title="You're all caught up." text="Holidays and HR updates will appear here." />
    @else
        @if($holidays->isNotEmpty())
            <div class="mb-4 flex flex-wrap gap-2">
                @foreach($holidays as $holiday)
                    <div class="flex min-w-0 items-center gap-2.5 rounded-lg border border-orange-100 bg-orange-50/50 px-2.5 py-1.5 dark:border-orange-500/15 dark:bg-orange-500/5">
                        <div class="flex w-8 shrink-0 flex-col items-center leading-none">
                            <span class="text-[10px] font-medium uppercase text-orange-600 dark:text-orange-400">{{ $holiday['date']->format('M') }}</span>
                            <span class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $holiday['date']->format('j') }}</span>
                        </div>
                        <div class="min-w-0">
                            <p class="truncate text-xs font-medium text-zinc-800 dark:text-zinc-100">{{ $holiday['name'] }}</p>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                {{ $holiday['days_away'] === 0 ? 'Today' : ($holiday['days_away'] === 1 ? 'Tomorrow' : 'In '.$holiday['days_away'].' days') }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        @if($updates->isNotEmpty())
            <ul class="-mx-2 space-y-0.5">
                @foreach($updates as $item)
                    @php $tag = $item['url'] ? 'a' : 'div'; @endphp
                    <li>
                        <{{ $tag }} @if($item['url']) href="{{ $item['url'] }}" @endif
                            class="flex items-start gap-3 rounded-lg px-2 py-2 transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-orange-500 dark:hover:bg-white/[0.03]">
                            <span class="relative mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-md bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                                <flux:icon :name="$item['icon']" class="size-3.5" />
                                @if($item['unread'])
                                    <span class="absolute -right-0.5 -top-0.5 size-2 rounded-full bg-orange-500 ring-2 ring-white dark:ring-ink-900" aria-label="Unread"></span>
                                @endif
                            </span>
                            <span class="min-w-0 flex-1">
                                <span @class(['block truncate text-sm', 'font-medium text-zinc-900 dark:text-white' => $item['unread'], 'text-zinc-700 dark:text-zinc-200' => ! $item['unread']])>{{ $item['title'] }}</span>
                                @if($item['sub'])
                                    <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $item['sub'] }}</span>
                                @endif
                            </span>
                            <span class="shrink-0 pt-0.5 text-[11px] text-zinc-400">{{ $item['time']?->diffForHumans(short: true) }}</span>
                        </{{ $tag }}>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</x-employee.dashboard.card>
