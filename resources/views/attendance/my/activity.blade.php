{{--
    Today Activity — today's effective events in time order (todayActivity():
    PunchTimeline punches with Face = IN / Card = OUT, web punches, break logs,
    the nightly auto checkout, regularised punches) with the day's state, the
    live clock and the clock-out / break actions. Hidden when nothing happened
    today.

    The clock counts HH:MM:SS from the open session's IN, only while the
    canonical state is "working". It is display only — it never writes
    anything; the 30-second refresh (refreshToday) re-reads the real values.
    Screen readers get a static "Currently working since …" label, not a
    per-second announcement. Motion is motion-safe only.
--}}
@php
    $events = $activity['events'];
    $state = $activity['state'];
    $visibleLimit = 8;
    $kindStyle = [
        'in' => ['icon' => 'arrow-right-end-on-rectangle', 'dot' => 'bg-gradient-to-b from-emerald-400 to-emerald-600 text-white ring-emerald-100 dark:ring-emerald-500/20', 'badge' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        'out' => ['icon' => 'arrow-left-start-on-rectangle', 'dot' => 'bg-gradient-to-b from-orange-400 to-orange-600 text-white ring-orange-100 dark:ring-orange-500/20', 'badge' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
        'break_start' => ['icon' => 'pause', 'dot' => 'bg-gradient-to-b from-amber-300 to-amber-500 text-white ring-amber-100 dark:ring-amber-500/20', 'badge' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
        'break_end' => ['icon' => 'play', 'dot' => 'bg-gradient-to-b from-sky-400 to-blue-600 text-white ring-sky-100 dark:ring-sky-500/20', 'badge' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300'],
        'auto' => ['icon' => 'clock', 'dot' => 'border-2 border-dashed border-orange-400 bg-orange-50 text-orange-600 ring-orange-50 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/10', 'badge' => 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-300'],
        'regularised' => ['icon' => 'pencil-square', 'dot' => 'bg-gradient-to-b from-violet-400 to-violet-600 text-white ring-violet-100 dark:ring-violet-500/20', 'badge' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
    ];
    $clockStart = $activity['clock_start_ms'];
    $elapsed = $clockStart ? max(0, intdiv((int) (now()->getTimestampMs() - $clockStart), 1000)) : 0;
    $initialClock = sprintf('%02d:%02d:%02d', intdiv($elapsed, 3600), intdiv($elapsed % 3600, 60), $elapsed % 60);
    $stateBadge = match ($state) {
        'working' => ['Currently Working', 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30', 'bg-emerald-500'],
        'break' => ['On Break', 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30', 'bg-amber-500'],
        'auto' => ['Auto Checkout', 'bg-orange-50 text-orange-700 ring-orange-200 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/30', 'bg-orange-500'],
        'missing' => ['Missing Checkout', 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30', 'bg-red-500'],
        'out' => ['Checked Out', 'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-white/[0.06] dark:text-slate-200 dark:ring-white/10', 'bg-zinc-400'],
        default => ['Not Working', 'bg-zinc-100 text-zinc-700 ring-zinc-200 dark:bg-white/[0.06] dark:text-slate-200 dark:ring-white/10', 'bg-zinc-400'],
    };
    $stateNote = match ($state) {
        'break' => 'Since '.($activeBreak?->break_start?->format('h:i A') ?? '—'),
        'auto' => 'Closed at '.($lastOut ?? '—').' — shift end',
        'missing' => 'No OUT punch — request regularisation',
        'out' => 'At '.($lastOut ?? '—'),
        default => null,
    };
    // While working, the shift end is shown as the next (not yet happened) point.
    $shiftEnd = $state === 'working' && $shiftWindow ? trim(\Illuminate\Support\Str::after($shiftWindow, '–')) : null;
@endphp

@if($events !== [])
<section class="{{ $ui['card'] }} relative overflow-hidden" aria-labelledby="today-activity-title" data-today-activity data-activity-state="{{ $state }}">
    <div class="pointer-events-none absolute inset-x-0 top-0 h-24 bg-gradient-to-b from-orange-50/70 to-transparent dark:from-orange-500/[0.04]" aria-hidden="true"></div>

    <div class="relative flex flex-wrap items-start justify-between gap-4 px-5 pt-5">
        <div class="flex min-w-0 items-start gap-3">
            <span class="{{ $ui['tile'] }} bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.bolt class="size-5" /></span>
            <div class="min-w-0">
                <h2 id="today-activity-title" class="{{ $ui['title'] }}">Today Activity</h2>
                <p class="{{ $ui['sub'] }} mt-0.5">Live view of your punches, breaks and status · updates every 30 seconds</p>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <span class="inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $stateBadge[1] }}" data-activity-badge>
                <span class="relative flex size-2" aria-hidden="true">
                    @if($state === 'working')<span class="absolute inline-flex size-full rounded-full bg-emerald-400 opacity-75 motion-safe:animate-ping"></span>@endif
                    <span class="relative inline-flex size-2 rounded-full {{ $stateBadge[2] }}"></span>
                </span>
                {{ $stateBadge[0] }}
            </span>

            @if($state === 'working' && $clockStart)
                <div wire:key="today-clock-{{ $clockStart }}" class="rounded-xl bg-emerald-50 px-3.5 py-1.5 text-center ring-1 ring-inset ring-emerald-100 dark:bg-emerald-500/10 dark:ring-emerald-500/20"
                     x-data="{ start: {{ $clockStart }}, now: Date.now(), timer: null,
                               init() { this.timer = setInterval(() => this.now = Date.now(), 1000) },
                               destroy() { clearInterval(this.timer) },
                               get clock() { const s = Math.max(0, Math.floor((this.now - this.start) / 1000)); const p = n => String(n).padStart(2, '0'); return p(Math.floor(s / 3600)) + ':' + p(Math.floor(s % 3600 / 60)) + ':' + p(s % 60); } }"
                     data-live-clock data-clock-start="{{ $clockStart }}" title="Running since your last IN">
                    <span class="sr-only">Currently working since {{ $activity['since'] }}</span>
                    <div class="font-mono text-[22px] font-bold leading-none tracking-tight tabular-nums text-emerald-700 dark:text-emerald-300" aria-hidden="true" x-text="clock">{{ $initialClock }}</div>
                    <div class="mt-1 text-[11px] text-emerald-800/80 dark:text-emerald-200/80" aria-hidden="true">Since {{ $activity['since'] }}</div>
                </div>
            @elseif($stateNote)
                <span class="text-xs text-zinc-500 dark:text-slate-400" @if($state === 'auto') title="{{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}" @endif data-activity-status>{{ $stateNote }}</span>
            @endif

            @if($isIn)
                <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'out' })" class="{{ $ui['btnPrimary'] }}">
                    <flux:icon.arrow-left-start-on-rectangle class="size-4" /> Clock Out
                </button>
                @if($activeBreak)
                    <button type="button" wire:click="endBreak" class="{{ $ui['btn'] }}"><flux:icon.play class="size-4" /> End Break</button>
                @else
                    <button type="button" wire:click="startBreak" class="{{ $ui['btn'] }}"><flux:icon.pause class="size-4" /> Start Break</button>
                @endif
            @endif

            <flux:dropdown position="bottom" align="end">
                <button type="button" class="{{ $ui['btn'] }} px-2.5" aria-label="More today actions"><flux:icon.ellipsis-vertical class="size-4" /></button>
                <flux:menu>
                    <flux:menu.item icon="list-bullet" x-on:click="$flux.modal('today-punches').show()">View all activity & raw punches</flux:menu.item>
                    <flux:menu.item icon="pencil-square" wire:click="openRegularisation('{{ today()->toDateString() }}')">Request regularisation</flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        </div>
    </div>

    @if($state === 'auto')
        <p class="relative mx-5 mt-3 flex gap-2 rounded-xl bg-orange-50/80 px-3 py-2 text-xs text-orange-800 dark:bg-orange-500/10 dark:text-orange-200"><flux:icon.information-circle class="size-4 shrink-0" /> {{ \App\Models\Attendance::AUTO_CHECKOUT_EXPLANATION }}</p>
    @endif

    {{-- Horizontal timeline: scrolls on small screens; fades + arrows show there is more. --}}
    <div class="relative px-3 pb-4 pt-4 sm:px-5"
         x-data="{ left: false, right: false,
                   sync() { const t = this.$refs.track; this.left = t.scrollLeft > 4; this.right = t.scrollLeft + t.clientWidth < t.scrollWidth - 4 },
                   go(dir) { this.$refs.track.scrollBy({ left: dir * this.$refs.track.clientWidth * 0.8, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' }) } }"
         x-init="$nextTick(() => { $refs.track.scrollLeft = $refs.track.scrollWidth; sync() })"
         @resize.window.debounce.150ms="sync()" data-activity-timeline>
        <div class="pointer-events-none absolute inset-y-0 left-0 z-10 w-14 bg-gradient-to-r from-white to-transparent transition-opacity dark:from-[#111A2E]" :class="left ? 'opacity-100' : 'opacity-0'" aria-hidden="true"></div>
        <div class="pointer-events-none absolute inset-y-0 right-0 z-10 w-14 bg-gradient-to-l from-white to-transparent transition-opacity dark:from-[#111A2E]" :class="right ? 'opacity-100' : 'opacity-0'" aria-hidden="true"></div>
        <button type="button" x-show="left" x-cloak @click="go(-1)" class="absolute left-2 top-[34px] z-20 flex size-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 shadow-md transition hover:text-orange-600 dark:border-white/10 dark:bg-[#18233A] dark:text-slate-200" aria-label="Scroll to earlier activity"><flux:icon.chevron-left class="size-4" /></button>
        <button type="button" x-show="right" x-cloak @click="go(1)" class="absolute right-2 top-[34px] z-20 flex size-9 items-center justify-center rounded-full border border-zinc-200 bg-white text-zinc-600 shadow-md transition hover:text-orange-600 dark:border-white/10 dark:bg-[#18233A] dark:text-slate-200" aria-label="Scroll to later activity"><flux:icon.chevron-right class="size-4" /></button>

        <div x-ref="track" @scroll.debounce.50ms="sync()" class="snap-x overflow-x-auto overscroll-x-contain pb-2 pt-1 [scrollbar-width:thin] motion-safe:scroll-smooth" tabindex="0" role="region" aria-label="Today's activity timeline, scroll horizontally" data-activity-track>
            <div class="relative mx-auto w-max">
                {{-- The line joining the nodes (centre of the first to centre of the last). --}}
                <div class="pointer-events-none absolute left-16 right-16 top-[26px] h-0.5 rounded-full bg-gradient-to-r from-emerald-200 via-orange-200 to-sky-200 dark:from-emerald-500/30 dark:via-orange-500/30 dark:to-sky-500/30" aria-hidden="true"></div>
                <ol class="relative flex" data-activity-events>
                    @foreach($events as $event)
                        @php $style = $kindStyle[$event['kind']] ?? $kindStyle['in']; @endphp
                        <li class="group relative flex w-32 shrink-0 snap-start flex-col items-center px-1 text-center" data-activity-event="{{ $event['kind'] }}" wire:key="activity-{{ $event['kind'] }}-{{ $event['ts'] }}">
                            <flux:tooltip position="bottom">
                                <button type="button" class="relative flex size-[52px] items-center justify-center rounded-full shadow-sm ring-[6px] transition focus:outline-none focus-visible:ring-orange-400 motion-safe:group-hover:scale-105 {{ $style['dot'] }}"
                                        aria-label="{{ $event['title'] }} at {{ $event['time'] }}{{ isset($event['details']['Status']) ? ', '.$event['details']['Status'] : '' }}">
                                    <flux:icon :name="$style['icon']" variant="mini" class="size-5" />
                                </button>
                                <flux:tooltip.content class="max-w-72 text-left">
                                    <div class="mb-1.5 border-b border-white/10 pb-1.5 text-[13px] font-semibold text-white">{{ $event['title'] }}</div>
                                    <dl class="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 p-0.5">
                                        @foreach($event['details'] as $term => $value)
                                            <dt class="text-zinc-400">{{ $term }}</dt>
                                            <dd class="font-medium text-white">{{ $value }}</dd>
                                        @endforeach
                                    </dl>
                                </flux:tooltip.content>
                            </flux:tooltip>
                            <div class="mt-2.5 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $event['time'] }}</div>
                            <div class="mt-0.5 line-clamp-1 text-xs text-zinc-600 dark:text-slate-300">{{ $event['title'] }}</div>
                            <span class="mt-1.5 inline-flex rounded-md px-2 py-0.5 text-[10px] font-bold tracking-wide {{ $style['badge'] }}">{{ $event['badge'] }}</span>
                        </li>
                    @endforeach
                    @if($shiftEnd)
                        <li class="relative flex w-32 shrink-0 snap-start flex-col items-center px-1 text-center opacity-80" data-activity-upcoming aria-label="Shift ends at {{ $shiftEnd }}">
                            <span class="flex size-[52px] items-center justify-center rounded-full border-2 border-dashed border-zinc-300 bg-white text-zinc-400 dark:border-white/15 dark:bg-[#111A2E] dark:text-slate-500" aria-hidden="true"><flux:icon.flag variant="mini" class="size-5" /></span>
                            <div class="mt-2.5 text-sm font-semibold tabular-nums text-zinc-500 dark:text-slate-400">{{ $shiftEnd }}</div>
                            <div class="mt-0.5 text-xs text-zinc-400 dark:text-slate-500">Shift ends</div>
                            <span class="mt-1.5 inline-flex rounded-md bg-zinc-100 px-2 py-0.5 text-[10px] font-bold tracking-wide text-zinc-500 dark:bg-white/[0.05] dark:text-slate-400">UPCOMING</span>
                        </li>
                    @endif
                </ol>
            </div>
        </div>
    </div>

    @if(count($events) > $visibleLimit)
        <div class="relative flex justify-end border-t border-zinc-100 px-5 py-3 dark:border-white/[0.06]">
            <button type="button" @click="$flux.modal('today-punches').show()" class="inline-flex items-center gap-1 text-sm font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400" data-activity-view-all>View All Activity <span aria-hidden="true">→</span></button>
        </div>
    @endif
</section>
@endif
