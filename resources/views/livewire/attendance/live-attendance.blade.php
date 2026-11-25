{{--
    Live Attendance — Live board, Activity, By Day, By Employee and Punch Audit
    for the people this viewer's view_live_attendance scope reaches. Live and
    Activity refresh themselves (wire:poll, this component only) at the chosen
    interval; the session clocks tick in the browser between refreshes.
--}}
@php
    $select = 'rounded-xl border border-zinc-200 bg-white py-2 pl-3 pr-8 text-sm font-medium text-zinc-700 focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200';
    $dateInput = 'rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200';
    $hm = fn (int $m): string => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
    $short = fn (int $m): string => $m >= 60 ? intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m' : $m.'m';
    // Department dots, in a fixed order so a department keeps its colour.
    $palette = ['bg-indigo-500', 'bg-teal-500', 'bg-red-500', 'bg-amber-500', 'bg-emerald-500', 'bg-violet-500', 'bg-rose-500', 'bg-sky-500', 'bg-lime-500', 'bg-fuchsia-500'];
    $deptDot = fn ($id): string => $palette[((int) $id) % count($palette)];
    $progressBar = ['on_track' => 'bg-emerald-500', 'nearing' => 'bg-lime-500', 'done' => 'bg-yellow-400', 'beyond' => 'bg-orange-500', 'way_beyond' => 'bg-rose-500'];
    $progressText = ['on_track' => 'text-emerald-600 dark:text-emerald-400', 'nearing' => 'text-lime-600 dark:text-lime-400', 'done' => 'text-yellow-600 dark:text-yellow-400', 'beyond' => 'text-orange-600 dark:text-orange-400', 'way_beyond' => 'text-rose-600 dark:text-rose-400'];
    $chipTone = [
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30',
        'red' => 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-200 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/30',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/30',
        'zinc' => 'bg-zinc-100 text-zinc-600 ring-zinc-200 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10',
    ];
    // The activity table's badges.
    $eventBadge = [
        'IN' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'OUT' => 'bg-zinc-100 text-zinc-700 dark:bg-white/5 dark:text-zinc-300',
        'BREAK' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'AUTO CHECKOUT' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'REGULARISED' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
    ];
    $statusTone = fn (string $s): string => match (true) {
        $s === 'Late' => 'text-amber-600 dark:text-amber-400',
        $s === 'On Time', $s === 'Working', $s === 'Back from break' => 'text-emerald-600 dark:text-emerald-400',
        $s === 'On Break' => 'text-sky-600 dark:text-sky-400',
        $s === 'Auto Checkout' => 'text-amber-600 dark:text-amber-400',
        str_starts_with($s, 'Regularised') => 'text-violet-600 dark:text-violet-300',
        default => 'text-zinc-600 dark:text-zinc-300',
    };
    $tabIcons = ['live' => 'signal', 'activity' => 'bolt', 'day' => 'calendar-days', 'employee' => 'user-circle', 'audit' => 'document-magnifying-glass'];
    $polling = in_array($tab, ['live', 'activity'], true) && $refresh > 0;
@endphp

<flux:main class="min-h-screen bg-[#F7F7F8] p-4 md:p-6">
<div class="mx-auto max-w-[1400px] space-y-4" @if($polling) wire:poll.{{ $refresh }}s @endif data-live-attendance
     x-data="{ offset: {{ $now_ms }} - Date.now(), now: {{ $now_ms }},
               init() { setInterval(() => this.now = Date.now() + this.offset, 1000) },
               clock(since) { const s = Math.max(0, Math.floor((this.now - since) / 1000)), h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60);
                   return (h ? h + ':' + String(m).padStart(2, '0') : String(m)) + ':' + String(s % 60).padStart(2, '0') } }">

    {{-- Title --}}
    <header class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Live Attendance</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">Who is in, who is out, and every punch · {{ $scope_label }}</p>
        </div>
        @if($polling)
            <div class="flex items-center gap-1.5 text-xs text-zinc-500 dark:text-zinc-400" wire:key="synced-{{ $syncedAt->getTimestampMs() }}">
                <span class="size-2 animate-pulse rounded-full bg-emerald-500"></span>
                <span class="font-semibold text-emerald-600">Live</span> · updated {{ $syncedAt->format('h:i:s A') }}
            </div>
        @endif
    </header>

    {{-- Tabs + view controls --}}
    <div class="ui-surface flex flex-wrap items-center justify-between gap-3 px-3 py-2">
        <nav class="-mb-px flex flex-wrap items-center gap-1" aria-label="Live attendance views">
            @foreach(\App\Livewire\Attendance\LiveAttendance::TABS as $key => $label)
                <button type="button" wire:click="$set('tab', '{{ $key }}')" data-tab="{{ $key }}" @class([
                    'inline-flex items-center gap-1.5 border-b-2 px-3 py-2 text-sm font-semibold transition',
                    'border-orange-500 text-orange-600 dark:text-orange-400' => $tab === $key,
                    'border-transparent text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-100' => $tab !== $key,
                ]) @if($tab === $key) aria-current="page" @endif>
                    <flux:icon :icon="$tabIcons[$key]" variant="mini" class="size-4" />
                    {{ $label }}
                    @if($key === 'live')<span class="size-1.5 rounded-full bg-emerald-500"></span>@endif
                </button>
            @endforeach
        </nav>

        @if(in_array($tab, ['live', 'activity'], true))
            <div class="flex flex-wrap items-center gap-2" data-live-controls>
                <div class="relative">
                    <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
                    <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search people…" aria-label="Search people"
                        class="w-48 rounded-xl border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 lg:w-56">
                </div>
                @if($tab === 'live')
                    <select wire:model.live="group" class="{{ $select }}" aria-label="Group">
                        @foreach(\App\Services\Attendance\LiveAttendanceService::GROUPS as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                    </select>
                    <select wire:model.live="sort" class="{{ $select }}" aria-label="Sort">
                        @foreach(\App\Services\Attendance\LiveAttendanceService::SORTS as $value => $label)<option value="{{ $value }}">Sort: {{ $label }}</option>@endforeach
                    </select>
                @endif
                <select wire:model.live="shift" class="{{ $select }}" aria-label="Shift">
                    <option value="">All shifts</option>
                    @foreach($shifts as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                </select>
                <select wire:model.live="refresh" class="{{ $select }}" aria-label="Refresh every" data-refresh>
                    @foreach(\App\Livewire\Attendance\LiveAttendance::REFRESH as $seconds)
                        <option value="{{ $seconds }}">{{ $seconds ? '● '.$seconds.'s' : 'Paused' }}</option>
                    @endforeach
                </select>
                @if($tab === 'live')
                    <div class="inline-flex rounded-xl border border-zinc-200 bg-white p-0.5 dark:border-zinc-700 dark:bg-zinc-900" role="group" aria-label="Layout">
                        @foreach(['grid' => 'squares-2x2', 'list' => 'list-bullet'] as $value => $icon)
                            <button type="button" wire:click="$set('layout', '{{ $value }}')" aria-label="{{ ucfirst($value) }} view" @class([
                                'rounded-lg p-1.5', 'bg-zinc-100 text-zinc-900 dark:bg-white/10 dark:text-white' => $layout === $value,
                                'text-zinc-400 hover:text-zinc-700' => $layout !== $value])>
                                <flux:icon :icon="$icon" variant="mini" class="size-4" />
                            </button>
                        @endforeach
                    </div>
                @endif
                <button type="button" wire:click="refreshNow" class="rounded-xl border border-zinc-200 bg-white p-2 text-zinc-500 hover:text-orange-600 dark:border-zinc-700 dark:bg-zinc-900" aria-label="Refresh now">
                    <flux:icon.arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="refreshNow" />
                </button>
            </div>
        @endif
    </div>

    @if($tab === 'live')
        @include('livewire.attendance.partials.live-board')
    @elseif($tab === 'activity')
        <section class="ui-surface" aria-labelledby="latest-activity">
            <div class="flex items-center justify-between px-5 pb-2 pt-5">
                <h2 id="latest-activity" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Latest Activity</h2>
                <span class="text-xs text-zinc-500">Newest first · {{ min(count($activity), $activity_total) }} of {{ $activity_total }}</span>
            </div>
            @include('livewire.attendance.partials.live-activity-table', ['events' => $activity])
            @if($activity_total > count($activity) && count($activity) < \App\Livewire\Attendance\LiveAttendance::MAX_ACTIVITY)
                <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                    <button type="button" wire:click="viewMoreActivity" class="text-sm font-semibold text-orange-600 hover:text-orange-700">View more activity</button>
                </div>
            @endif
        </section>
    @elseif($tab === 'day')
        @include('livewire.attendance.partials.live-by-day')
    @elseif($tab === 'employee')
        @include('livewire.attendance.partials.live-by-employee')
    @else
        @include('livewire.attendance.partials.live-audit')
    @endif
</div>
</flux:main>
