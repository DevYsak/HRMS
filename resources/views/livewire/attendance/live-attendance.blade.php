{{--
    Live Attendance — today's activity and status for the people this viewer's
    view_live_attendance scope reaches. wire:poll refreshes the panel every
    30 seconds (only this component — never the page).
--}}
@php
    $card = 'rounded-2xl border border-zinc-200/80 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900';
    $select = 'rounded-xl border border-zinc-200 bg-white py-2 pl-3 pr-8 text-sm text-zinc-700 focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200';
    $hm = fn (int $m): string => intdiv($m, 60).'h '.str_pad((string) ($m % 60), 2, '0', STR_PAD_LEFT).'m';
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
    $rowTone = [
        'green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'red' => 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400',
        'blue' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'zinc' => 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300',
    ];
    $summaryCards = [
        ['present', 'Present Now', 'text-emerald-600'],
        ['late', 'Late Today', 'text-amber-600'],
        ['not_in', 'Not Checked In', 'text-zinc-900 dark:text-white'],
        ['checked_out', 'Checked Out', 'text-zinc-900 dark:text-white'],
        ['missing', 'Missing Checkout', 'text-red-600'],
        ['on_break', 'On Break', 'text-sky-600'],
    ];
    $attentionItems = [
        ['late', 'Late arrivals'],
        ['missing', 'Missing checkout'],
        ['not_in', 'No check-in'],
        ['excess_break', 'Excess break'],
    ];
@endphp

<flux:main class="min-h-screen bg-[#F7F7F8] p-4 md:p-6 dark:bg-zinc-950">
<div class="mx-auto max-w-[1360px] space-y-6" wire:poll.30s data-live-attendance>

    {{-- Header --}}
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-[30px] font-bold leading-tight tracking-tight text-zinc-900 dark:text-white">Live Attendance</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Real-time attendance activity for today · {{ $scope_label }}</p>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right text-xs text-zinc-500 dark:text-zinc-400" wire:key="synced-{{ $syncedAt->getTimestampMs() }}"
                 x-data="{ at: {{ $syncedAt->getTimestampMs() }}, ago: 'just now',
                           tick() { const s = Math.max(0, Math.round((Date.now() - this.at) / 1000)); this.ago = s < 5 ? 'just now' : (s < 60 ? s + ' sec ago' : Math.floor(s / 60) + ' min ago'); } }"
                 x-init="tick(); setInterval(() => tick(), 1000)">
                <div class="flex items-center justify-end gap-1.5 font-semibold text-emerald-600"><span class="size-2 animate-pulse rounded-full bg-emerald-500"></span> Live</div>
                <div>Updated <span x-text="ago">just now</span> · Last synced: {{ $syncedAt->format('h:i:s A') }}</div>
            </div>
            <button type="button" wire:click="refreshNow" class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 bg-white px-3.5 py-2 text-sm font-semibold text-zinc-700 hover:border-orange-300 hover:text-orange-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                <flux:icon.arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="refreshNow" /> Refresh now
            </button>
        </div>
    </header>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-2" data-live-filters>
        <select wire:model.live="department" class="{{ $select }}" aria-label="Department">
            <option value="">All Departments</option>
            @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
        </select>
        <select wire:model.live="shift" class="{{ $select }}" aria-label="Shift">
            <option value="">All Shifts</option>
            @foreach($shifts as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
        </select>
        <select wire:model.live="status" class="{{ $select }}" aria-label="Status">
            <option value="">All Statuses</option>
            @foreach($statusOptions as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
        </select>
        <div class="relative min-w-[12rem] flex-1 sm:flex-none">
            <flux:icon.magnifying-glass class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-400" />
            <input type="search" wire:model.live.debounce.400ms="search" placeholder="Search employee…" aria-label="Search employee"
                class="w-full rounded-xl border border-zinc-200 bg-white py-2 pl-9 pr-3 text-sm focus:border-orange-400 focus:ring-0 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 sm:w-56">
        </div>
        <span class="text-xs text-zinc-400">Today · {{ now()->format('D, d M') }}</span>
    </div>

    {{-- Summary --}}
    <section class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6" aria-label="Today at a glance" data-live-summary>
        @foreach($summaryCards as [$key, $label, $tone])
            <div class="{{ $card }}" data-summary="{{ $key }}">
                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                <div class="mt-1 text-[28px] font-bold leading-none tabular-nums {{ $tone }}">{{ $summary[$key] }}</div>
            </div>
        @endforeach
    </section>

    {{-- Tabs --}}
    <nav class="flex gap-1 rounded-xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-800 dark:bg-zinc-900 sm:inline-flex" aria-label="Live attendance views">
        @foreach(['overview' => 'Overview', 'activity' => 'Latest Activity', 'status' => 'Today Status'] as $key => $label)
            <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                'flex-1 rounded-lg px-4 py-2 text-sm font-semibold transition sm:flex-none',
                'bg-orange-500 text-white shadow-sm' => $tab === $key,
                'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-white/5' => $tab !== $key,
            ]) @if($tab === $key) aria-current="page" @endif>{{ $label }}</button>
        @endforeach
    </nav>

    @if($tab === 'overview')
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,20rem)]">
            <section class="min-w-0 rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="overview-activity">
                <div class="flex items-center justify-between px-5 pb-2 pt-5">
                    <h2 id="overview-activity" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Latest Activity</h2>
                    @if($activity_total > 0)
                        <button type="button" wire:click="$set('tab', 'activity')" class="text-xs font-semibold text-orange-600 hover:text-orange-700">View all</button>
                    @endif
                </div>
                @include('livewire.attendance.partials.live-activity-table', ['events' => array_slice($activity, 0, 8)])
            </section>
            <section class="rounded-2xl border border-zinc-200/80 bg-white p-5 shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="needs-attention" data-live-attention>
                <h2 id="needs-attention" class="mb-3 text-[17px] font-semibold text-zinc-900 dark:text-white">Needs Attention</h2>
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach($attentionItems as [$key, $label])
                        <li>
                            <button type="button" wire:click="applyAttention('{{ $key }}')" class="flex w-full items-center justify-between py-2.5 text-left text-sm hover:text-orange-600" data-attention="{{ $key }}">
                                <span class="text-zinc-600 dark:text-zinc-300">{{ $label }}</span>
                                <span @class(['font-bold tabular-nums', 'text-amber-600' => $attention[$key] > 0, 'text-zinc-400' => $attention[$key] === 0])>{{ $attention[$key] }}</span>
                            </button>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    @elseif($tab === 'activity')
        <section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="latest-activity">
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
    @else
        <section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="today-status">
            <div class="flex items-center justify-between px-5 pb-2 pt-5">
                <h2 id="today-status" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Today Status</h2>
                <span class="text-xs text-zinc-500">{{ count($rows) }} {{ \Illuminate\Support\Str::plural('employee', count($rows)) }}</span>
            </div>
            @if($rows === [])
                <p class="px-5 pb-8 pt-2 text-sm text-zinc-500">No employees match these filters.</p>
            @else
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[820px] text-sm" data-live-status>
                        <thead>
                            <tr class="border-y border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                <th class="px-5 py-2 font-medium">Employee</th>
                                <th class="px-3 py-2 font-medium">Department</th>
                                <th class="px-3 py-2 font-medium">Shift</th>
                                <th class="px-3 py-2 font-medium">First In</th>
                                <th class="px-3 py-2 font-medium">Last Out</th>
                                <th class="px-3 py-2 text-right font-medium">Worked</th>
                                <th class="px-3 py-2 font-medium">Current Status</th>
                                <th class="px-5 py-2 font-medium">Exception</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach(array_slice($rows, 0, $statusLimit) as $r)
                                <tr class="text-zinc-700 dark:text-zinc-300" data-live-row="{{ $r['id'] }}">
                                    <td class="px-5 py-2.5 font-medium text-zinc-900 dark:text-white">{{ $r['name'] }}</td>
                                    <td class="px-3 py-2.5">{{ $r['department'] }}</td>
                                    <td class="px-3 py-2.5 text-xs text-zinc-500">{{ $r['shift'] }}</td>
                                    <td class="px-3 py-2.5 tabular-nums">{{ $r['first_in'] ?? '—' }}</td>
                                    <td class="px-3 py-2.5 tabular-nums">{{ $r['last_out'] ?? '—' }}</td>
                                    <td class="px-3 py-2.5 text-right tabular-nums">{{ $r['first_in'] ? $hm($r['worked_minutes']) : '—' }}</td>
                                    <td class="px-3 py-2.5"><span class="whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $rowTone[$r['tone']] }}">{{ $r['current'] }}</span></td>
                                    <td class="px-5 py-2.5">
                                        @forelse($r['exceptions'] as $ex)
                                            <span class="mr-1 whitespace-nowrap rounded bg-zinc-100 px-1.5 py-0.5 text-[11px] font-semibold text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $ex }}</span>
                                        @empty
                                            <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                        @endforelse
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @if(count($rows) > $statusLimit)
                    <div class="border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
                        <button type="button" wire:click="showMoreStatus" class="text-sm font-semibold text-orange-600 hover:text-orange-700">Show {{ min(50, count($rows) - $statusLimit) }} more</button>
                    </div>
                @endif
            @endif
        </section>
    @endif
</div>
</flux:main>
