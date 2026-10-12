{{--
    Today Activity — today's effective events in time order (todayActivity():
    PunchTimeline punches with Face = IN / Card = OUT, web punches, break logs,
    the nightly auto checkout, regularised punches). Hidden when nothing
    happened today.

    The clock on the left counts HH:MM:SS from the open session's IN, only
    while the canonical state is "working". It is display only — it never
    writes anything; the 30-second refresh (refreshToday) re-reads the real
    values. Screen readers get a static "Currently working since …" label, not
    a per-second announcement. Motion is motion-safe only.
--}}
@php
    $events = $activity['events'];
    $state = $activity['state'];
    $visibleLimit = 8;
    $kindStyle = [
        'in' => ['icon' => 'arrow-right-end-on-rectangle', 'dot' => 'bg-emerald-500 text-white ring-emerald-100 dark:ring-emerald-500/20', 'badge' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        'out' => ['icon' => 'arrow-left-start-on-rectangle', 'dot' => 'bg-orange-500 text-white ring-orange-100 dark:ring-orange-500/20', 'badge' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400'],
        'break_start' => ['icon' => 'pause', 'dot' => 'bg-amber-400 text-white ring-amber-100 dark:ring-amber-500/20', 'badge' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
        'break_end' => ['icon' => 'play', 'dot' => 'bg-sky-500 text-white ring-sky-100 dark:ring-sky-500/20', 'badge' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        'auto' => ['icon' => 'bolt', 'dot' => 'border-2 border-dashed border-white bg-amber-500 text-white ring-amber-200 dark:border-zinc-900 dark:ring-amber-500/30', 'badge' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
        'regularised' => ['icon' => 'pencil-square', 'dot' => 'bg-violet-500 text-white ring-violet-100 dark:ring-violet-500/20', 'badge' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
    ];
    $clockStart = $activity['clock_start_ms'];
    $elapsed = $clockStart ? max(0, intdiv((int) (now()->getTimestampMs() - $clockStart), 1000)) : 0;
    $initialClock = sprintf('%02d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60);
    $stateCard = match ($state) {
        'break' => ['On Break', 'Since '.($activeBreak?->break_start?->format('h:i A') ?? '—'), 'text-amber-600 dark:text-amber-400', 'bg-amber-400'],
        'auto' => ['Auto Checkout', 'Closed at '.($lastOut ?? '—').' — shift end', 'text-amber-700 dark:text-amber-400', 'bg-amber-500'],
        'missing' => ['Missing Checkout', 'No OUT punch — request regularisation', 'text-red-600 dark:text-red-400', 'bg-red-500'],
        'out' => ['Checked Out', 'At '.($lastOut ?? '—'), 'text-zinc-900 dark:text-white', 'bg-zinc-400'],
        default => ['Not Working', 'No open session', 'text-zinc-900 dark:text-white', 'bg-zinc-300'],
    };
@endphp

@if($events !== [])
<section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="today-activity-title" data-today-activity data-activity-state="{{ $state }}">
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 pb-1 pt-5">
        <div>
            <h2 id="today-activity-title" class="flex items-center gap-3 text-[17px] font-semibold text-zinc-900 dark:text-white"><flux:icon.bolt class="size-6 text-orange-500" /> Today Activity</h2>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">Your punch activity for today · {{ count($events) }} {{ \Illuminate\Support\Str::plural('event', count($events)) }}</p>
        </div>
        @if(count($events) > $visibleLimit)
            <button type="button" @click="$flux.modal('today-activity').show()" class="inline-flex items-center gap-1 text-sm font-semibold text-orange-600 hover:text-orange-700" data-activity-view-all>View All Activity <span aria-hidden="true">→</span></button>
        @endif
    </div>

    <div class="flex flex-col gap-4 p-5 pt-3 md:flex-row md:items-stretch">
        {{-- Left: live clock (working) or the day's state. --}}
        @if($state === 'working' && $clockStart)
            <div wire:key="today-clock-{{ $clockStart }}" class="flex shrink-0 flex-col justify-center rounded-xl border border-emerald-200 bg-emerald-50/70 px-4 py-3 md:w-52 dark:border-emerald-500/20 dark:bg-emerald-500/10"
                 x-data="{ start: {{ $clockStart }}, now: Date.now(), timer: null,
                           init() { this.timer = setInterval(() => this.now = Date.now(), 1000) },
                           destroy() { clearInterval(this.timer) },
                           get clock() { const s = Math.max(0, Math.floor((this.now - this.start) / 1000)); const p = n => String(n).padStart(2, '0'); return p(Math.floor(s / 3600)) + ':' + p(Math.floor(s % 3600 / 60)) + ':' + p(s % 60); } }"
                 data-live-clock data-clock-start="{{ $clockStart }}">
                <span class="sr-only">Currently working since {{ $activity['since'] }}</span>
                <div class="font-mono text-[28px] font-bold leading-none tracking-tight tabular-nums text-emerald-700 dark:text-emerald-300" aria-hidden="true" x-text="clock">{{ $initialClock }}</div>
                <div class="mt-1.5 text-xs text-emerald-800/80 dark:text-emerald-200/80" aria-hidden="true">Since last IN · {{ $activity['since'] }}</div>
                <div class="mt-2 inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-700 dark:text-emerald-300" aria-hidden="true">
                    <span class="relative flex size-2"><span class="absolute inline-flex size-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"></span><span class="relative inline-flex size-2 rounded-full bg-emerald-500"></span></span>
                    Active
                </div>
            </div>
        @else
            <div class="flex shrink-0 flex-col justify-center rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3 md:w-52 dark:border-zinc-700 dark:bg-white/[0.03]" data-activity-status>
                <div class="flex items-center gap-2 text-base font-bold {{ $stateCard[2] }}"><span class="size-2 rounded-full {{ $stateCard[3] }}" aria-hidden="true"></span>{{ $stateCard[0] }}</div>
                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400" @if($state === 'auto') title="{{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}" @endif>{{ $stateCard[1] }}</div>
                @if($state === 'auto')
                    <p class="mt-2 text-[11px] leading-snug text-zinc-500 dark:text-zinc-400">{{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}</p>
                @endif
            </div>
        @endif

        {{-- Right: horizontal timeline (scrolls on small screens; fades + arrows show there is more). --}}
        <div class="relative min-w-0 flex-1"
             x-data="{ left: false, right: false,
                       sync() { const t = this.$refs.track; this.left = t.scrollLeft > 4; this.right = t.scrollLeft + t.clientWidth < t.scrollWidth - 4 },
                       go(dir) { this.$refs.track.scrollBy({ left: dir * this.$refs.track.clientWidth * 0.8, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }) } }"
             x-init="$nextTick(() => { $refs.track.scrollLeft = $refs.track.scrollWidth; sync() })"
             @resize.window.debounce.150ms="sync()">
            <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-10 bg-gradient-to-r from-white to-transparent transition-opacity dark:from-zinc-900" :class="left ? 'opacity-100' : 'opacity-0'" aria-hidden="true"></div>
            <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-10 bg-gradient-to-l from-white to-transparent transition-opacity dark:from-zinc-900" :class="right ? 'opacity-100' : 'opacity-0'" aria-hidden="true"></div>
            <button type="button" x-show="left" x-cloak @click="go(-1)" class="absolute left-0 top-[14px] z-20 flex size-8 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 shadow-sm hover:text-orange-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" aria-label="Scroll to earlier activity"><flux:icon.chevron-left class="size-4" /></button>
            <button type="button" x-show="right" x-cloak @click="go(1)" class="absolute right-0 top-[14px] z-20 flex size-8 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 shadow-sm hover:text-orange-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300" aria-label="Scroll to later activity"><flux:icon.chevron-right class="size-4" /></button>

            <div x-ref="track" @scroll.debounce.50ms="sync()" class="overflow-x-auto overscroll-x-contain pb-2 pt-1 [scrollbar-width:thin] motion-safe:scroll-smooth snap-x" tabindex="0" role="region" aria-label="Today's activity timeline, scroll horizontally" data-activity-track>
                <div class="relative w-max">
                    {{-- The line joining the nodes (centre of the first to centre of the last). --}}
                    <div class="pointer-events-none absolute left-14 right-14 top-[22px] h-0.5 rounded-full bg-zinc-200 dark:bg-zinc-700" aria-hidden="true">
                        @if($state === 'working')
                            <span class="absolute inset-y-0 right-0 w-16 rounded-full bg-gradient-to-r from-transparent to-emerald-400 motion-safe:animate-pulse"></span>
                        @endif
                    </div>
                    <ol class="relative flex" data-activity-events>
                        @foreach($events as $i => $event)
                            @php $style = $kindStyle[$event['kind']] ?? $kindStyle['in']; @endphp
                            <li class="relative flex w-28 shrink-0 snap-start flex-col items-center px-1 text-center" data-activity-event="{{ $event['kind'] }}" wire:key="activity-{{ $event['kind'] }}-{{ $event['ts'] }}">
                                <flux:tooltip position="bottom">
                                    <button type="button" class="relative flex size-11 items-center justify-center rounded-full ring-4 focus:outline-none focus-visible:ring-orange-400 {{ $style['dot'] }}"
                                            aria-label="{{ $event['title'] }} at {{ $event['time'] }}{{ isset($event['details']['Status']) ? ', '.$event['details']['Status'] : '' }}">
                                        <flux:icon :name="$style['icon']" variant="mini" class="relative size-5" />
                                    </button>
                                    <flux:tooltip.content class="max-w-64 text-left">
                                        <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 p-0.5">
                                            @foreach($event['details'] as $term => $value)
                                                <dt class="text-zinc-400">{{ $term }}</dt>
                                                <dd class="font-medium text-white">{{ $value }}</dd>
                                            @endforeach
                                        </dl>
                                    </flux:tooltip.content>
                                </flux:tooltip>
                                <div class="mt-2 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $event['time'] }}</div>
                                <div class="mt-0.5 line-clamp-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $event['title'] }}</div>
                                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide {{ $style['badge'] }}">{{ $event['badge'] }}</span>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </div>
    </div>
</section>

@if(count($events) > $visibleLimit)
    <flux:modal name="today-activity" flyout class="w-full md:w-[30rem]" data-activity-all>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Today Activity</flux:heading>
                <flux:subheading>{{ now()->format('l, d M Y') }} · {{ count($events) }} events</flux:subheading>
            </div>
            <ol class="relative space-y-4 border-l border-zinc-200 pl-6 dark:border-zinc-700">
                @foreach($events as $event)
                    @php $style = $kindStyle[$event['kind']] ?? $kindStyle['in']; @endphp
                    <li class="relative">
                        <span class="absolute -left-[37px] top-0 flex size-6 items-center justify-center rounded-full ring-4 {{ $style['dot'] }}" aria-hidden="true"><flux:icon :name="$style['icon']" variant="micro" class="size-3.5" /></span>
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $event['time'] }}</span>
                            <span class="text-sm text-zinc-700 dark:text-zinc-200">{{ $event['title'] }}</span>
                            <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold tracking-wide {{ $style['badge'] }}">{{ $event['badge'] }}</span>
                        </div>
                        <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 gap-y-0.5 text-xs">
                            @foreach($event['details'] as $term => $value)
                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $term }}</dt>
                                <dd class="text-zinc-700 dark:text-zinc-200">{{ $value }}</dd>
                            @endforeach
                        </dl>
                    </li>
                @endforeach
            </ol>
        </div>
    </flux:modal>
@endif
@endif
