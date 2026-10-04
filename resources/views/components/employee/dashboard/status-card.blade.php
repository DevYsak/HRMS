@props(['today'])

@php
    $workedLabel = \App\Services\EmployeeDashboardService::formatMinutes($today['worked_minutes']);

    $badge = match ($today['state']) {
        'present', 'wfh' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/15 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-400/20',
        'late', 'half_day' => 'bg-amber-50 text-amber-700 ring-amber-600/15 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-400/20',
        'absent' => 'bg-rose-50 text-rose-700 ring-rose-600/15 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-400/20',
        'holiday', 'leave' => 'bg-orange-50 text-orange-700 ring-orange-600/15 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-400/20',
        'mdl' => 'bg-indigo-50 text-indigo-700 ring-indigo-600/15 dark:bg-indigo-500/10 dark:text-indigo-300 dark:ring-indigo-400/20',
        default => 'bg-zinc-100 text-zinc-600 ring-zinc-500/15 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    };
    $dot = match ($today['state']) {
        'present', 'wfh' => 'bg-emerald-500',
        'late', 'half_day' => 'bg-amber-500',
        'absent' => 'bg-rose-500',
        'holiday', 'leave' => 'bg-orange-500',
        'mdl' => 'bg-indigo-400',
        default => 'bg-zinc-400',
    };

    $progress = $today['progress'];
    $caption = match (true) {
        $today['on_break'] => 'On break since '.$today['break_since'],
        $today['working'] => 'Working time',
        default => 'Worked today',
    };

    $primaryBtn = 'inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-orange-500 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-orange-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 disabled:opacity-60';
    $secondaryBtn = 'inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 disabled:opacity-60 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-white/10';
@endphp

{{-- EmployeeStatusCard — today's state, a live working clock and the punch
     actions. Punches go through the dashboard's clockIn()/clockOut(), which
     delegate to AttendanceService; breaks through startBreak()/endBreak(). --}}
<div {{ $attributes->class('rounded-xl border border-zinc-200/80 bg-zinc-50/80 p-4 dark:border-white/[0.06] dark:bg-white/[0.03]') }}>
    <div class="flex items-center justify-between gap-2">
        <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Today's status</span>
        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $badge }}">
            <span class="size-1.5 rounded-full {{ $dot }}" aria-hidden="true"></span>
            {{ $today['label'] }}
        </span>
    </div>

    {{-- The counter reads its base from data attributes on every tick. A punch
         or break re-render morphs those attributes in place while Alpine keeps
         this component alive, so the clock starts, pauses and resumes without
         a page reload. An empty data-base means "stand still". --}}
    <div class="mt-3"
         data-base="{{ $today['live_base'] }}"
         data-static="{{ $workedLabel }}"
         x-data="{
            text: $el.dataset.static,
            timer: null,
            tick() {
                const base = parseInt(this.$root.dataset.base, 10);
                if (Number.isNaN(base)) { this.text = this.$root.dataset.static; return; }
                const s = Math.max(0, Math.floor(Date.now() / 1000) - base);
                const h = Math.floor(s / 3600), m = Math.floor((s % 3600) / 60), sec = s % 60;
                this.text = h + 'h ' + String(m).padStart(2, '0') + 'm ' + String(sec).padStart(2, '0') + 's';
            },
            init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000); },
            destroy() { clearInterval(this.timer); },
         }">
        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">{{ $caption }}</p>
        <p class="mt-0.5 text-3xl font-semibold tabular-nums tracking-tight text-zinc-900 dark:text-white" x-text="text" aria-live="off">{{ $workedLabel }}</p>
        @if($today['detail'])
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $today['detail'] }}</p>
        @endif
    </div>

    @if($progress['measurable'])
        <div class="mt-3">
            <div class="h-1.5 overflow-hidden rounded-full bg-zinc-200/80 dark:bg-white/10"
                 role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $progress['percent'] }}" aria-label="Shift progress">
                <div class="h-full rounded-full bg-orange-500 transition-[width] duration-700" style="width: {{ $progress['percent'] }}%"></div>
            </div>
            <div class="mt-1.5 flex justify-between text-[11px] text-zinc-500 dark:text-zinc-400">
                <span>{{ $progress['percent'] }}% of {{ $progress['expected_label'] }}</span>
                <span>{{ $progress['remaining_label'] }} left</span>
            </div>
        </div>
    @endif

    <div class="mt-4 flex flex-wrap gap-2"
         x-data="{
            punching: false,
            mode: @js($today['default_mode'] ?? 'office'),
            punch(action) {
                if (this.punching) return;
                this.punching = true;
                const send = (lat, lng) => (action === 'clockIn'
                    ? $wire.call(action, lat, lng, this.mode)
                    : $wire.call(action, lat, lng)).finally(() => this.punching = false);
                if (! ('geolocation' in navigator)) { send(null, null); return; }
                navigator.geolocation.getCurrentPosition(
                    p => send(+p.coords.latitude.toFixed(6), +p.coords.longitude.toFixed(6)),
                    () => send(null, null),
                    { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
                );
            }
         }">
        @if(! $today['clocked_in'])
            {{-- Spec §3.2: work mode (Office / WFH) is selected at clock-in — on a
                 day with approved work from home. Without one the punch is an
                 office punch, and clockIn() refuses "wfh" as well. --}}
            @if(($today['default_mode'] ?? 'office') === 'wfh')
                <div class="inline-flex rounded-lg bg-zinc-200/70 p-0.5 text-xs font-medium dark:bg-white/10" role="radiogroup" aria-label="Work mode">
                    <button type="button" role="radio" x-on:click="mode = 'office'" x-bind:aria-checked="mode === 'office'"
                            x-bind:class="mode === 'office' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' : 'text-zinc-500'"
                            class="rounded-md px-2.5 py-1.5 transition">Office</button>
                    <button type="button" role="radio" x-on:click="mode = 'wfh'" x-bind:aria-checked="mode === 'wfh'"
                            x-bind:class="mode === 'wfh' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-800 dark:text-white' : 'text-zinc-500'"
                            class="rounded-md px-2.5 py-1.5 transition">WFH</button>
                </div>
            @endif
            <button type="button" x-on:click="punch('clockIn')" x-bind:disabled="punching"
                    class="{{ $today['is_working_day'] ? $primaryBtn : $secondaryBtn }}">
                <flux:icon.arrow-right-end-on-rectangle class="size-4" />
                <span x-text="punching ? 'Clocking in…' : 'Clock In'">Clock In</span>
            </button>
        @elseif($today['working'])
            @if($today['on_break'])
                <button type="button" wire:click="endBreak" wire:loading.attr="disabled" wire:target="endBreak" class="{{ $primaryBtn }}">
                    <flux:icon.play class="size-4" /> End break
                </button>
                <button type="button" x-on:click="punch('clockOut')" x-bind:disabled="punching" class="{{ $secondaryBtn }}">
                    <flux:icon.arrow-left-start-on-rectangle class="size-4" /> Clock Out
                </button>
            @else
                <button type="button" x-on:click="punch('clockOut')" x-bind:disabled="punching" class="{{ $primaryBtn }}">
                    <flux:icon.arrow-left-start-on-rectangle class="size-4" />
                    <span x-text="punching ? 'Clocking out…' : 'Clock Out'">Clock Out</span>
                </button>
                <button type="button" wire:click="startBreak" wire:loading.attr="disabled" wire:target="startBreak" class="{{ $secondaryBtn }}">
                    <flux:icon.pause class="size-4" /> Break
                </button>
            @endif
        @else
            <a href="{{ route('attendance.my') }}" wire:navigate class="{{ $secondaryBtn }}">
                <flux:icon.clock class="size-4" /> View attendance
            </a>
        @endif
    </div>
</div>
