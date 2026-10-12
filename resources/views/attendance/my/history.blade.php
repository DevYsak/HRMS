{{--
    Attendance History — the selected month, newest first, from monthHistory.
    Upcoming dates are left out. Filters (date range inside the month, status,
    mode) narrow the rows live without reloading the page — they only match the
    statuses already shown, nothing is recalculated. The card shows the latest
    8 days; "View full history" opens the filtered month in a right-side
    drawer. A day with punches opens its detail (punch-detail); "Why?" explains
    the engine's decision.
--}}
@php
    $monthRows = collect($mh['rows'] ?? [])
        // Future dates are left out whatever their type (a future weekly off or holiday too).
        ->reject(fn (array $r) => $r['date'] > today()->toDateString() || $r['status'] === 'Not employed');
    $historyRows = $this->filterHistoryRows($monthRows)->reverse()->values();
    $filtersActive = $historyFrom !== '' || $historyTo !== '' || $historyStatus !== '' || $historyMode !== '';
    $monthStart = \Illuminate\Support\Carbon::createFromFormat('!Y-m', $mh['month'])->startOfMonth();
    $monthEnd = $monthStart->copy()->endOfMonth()->min(today());
    $tone = [
        'green' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
        'amber' => 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
        'red' => 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-400',
        'blue' => 'bg-indigo-50 text-indigo-700 dark:bg-indigo-500/10 dark:text-indigo-300',
        'sky' => 'bg-sky-50 text-sky-700 dark:bg-sky-500/10 dark:text-sky-300',
        'violet' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'rose' => 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300',
        'muted' => 'bg-zinc-100 text-zinc-500 dark:bg-white/5 dark:text-zinc-400',
    ];
    $todayKey = today()->toDateString();
    $previewRows = 8;
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="history-title" data-history>
    <div class="flex flex-wrap items-baseline justify-between gap-2 px-5 pb-3 pt-5">
        <h2 id="history-title" class="flex items-center gap-3 text-[17px] font-semibold text-zinc-900 dark:text-white"><flux:icon.calendar-days class="size-6 text-orange-500" /> Attendance History</h2>
        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $mh['label'] }} · click a day for its punches</span>
    </div>

    {{-- Filters — Livewire updates the table in place (no page reload). --}}
    <div class="grid grid-cols-2 gap-3 border-y border-zinc-100 bg-zinc-50/60 px-5 py-3 sm:grid-cols-4 lg:grid-cols-[1fr_1fr_1fr_1fr_auto] lg:items-end dark:border-zinc-800 dark:bg-white/[0.02]" role="group" aria-label="Filter attendance history" data-history-filters>
        <label class="block min-w-0 text-xs font-medium text-zinc-600 dark:text-zinc-300">From
            <input type="date" wire:model.live="historyFrom" min="{{ $monthStart->toDateString() }}" max="{{ $monthEnd->toDateString() }}"
                   class="mt-1 block w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-400/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" data-filter="from">
        </label>
        <label class="block min-w-0 text-xs font-medium text-zinc-600 dark:text-zinc-300">To
            <input type="date" wire:model.live="historyTo" min="{{ $monthStart->toDateString() }}" max="{{ $monthEnd->toDateString() }}"
                   class="mt-1 block w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-400/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" data-filter="to">
        </label>
        <label class="block min-w-0 text-xs font-medium text-zinc-600 dark:text-zinc-300">Status
            <select wire:model.live="historyStatus"
                    class="mt-1 block w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-400/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" data-filter="status">
                <option value="">All</option>
                @foreach(\App\Livewire\Attendance\AttendanceTracker::HISTORY_STATUSES as $value => $text)
                    <option value="{{ $value }}">{{ $text }}</option>
                @endforeach
            </select>
        </label>
        <label class="block min-w-0 text-xs font-medium text-zinc-600 dark:text-zinc-300">Mode
            <select wire:model.live="historyMode"
                    class="mt-1 block w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-2.5 py-1.5 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-400/30 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" data-filter="mode">
                <option value="">All</option>
                <option value="office">Office</option>
                <option value="wfh">WFH</option>
                <option value="hybrid">Hybrid</option>
            </select>
        </label>
        <div class="col-span-2 flex items-center justify-between gap-3 sm:col-span-4 lg:col-span-1 lg:justify-end">
            <span class="text-xs text-zinc-500 dark:text-zinc-400 lg:hidden" aria-live="polite">{{ $historyRows->count() }} {{ \Illuminate\Support\Str::plural('day', $historyRows->count()) }}</span>
            <button type="button" wire:click="clearHistoryFilters" @disabled(! $filtersActive)
                    class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-semibold text-orange-600 hover:bg-orange-50 disabled:cursor-not-allowed disabled:text-zinc-400 disabled:hover:bg-transparent dark:hover:bg-orange-500/10" data-filter="clear">
                <flux:icon.x-mark class="size-4" /> Clear Filters
            </button>
        </div>
    </div>

    @if($monthRows->isEmpty())
        <p class="px-5 pb-6 pt-4 text-sm text-zinc-500 dark:text-zinc-400">No attendance days in {{ $mh['label'] }} yet.</p>
    @elseif($historyRows->isEmpty())
        <p class="px-5 pb-6 pt-4 text-sm text-zinc-500 dark:text-zinc-400" data-history-no-match>No days in {{ $mh['label'] }} match these filters.
            <button type="button" wire:click="clearHistoryFilters" class="font-semibold text-orange-600 hover:text-orange-700">Clear filters</button></p>
    @else
        <div class="relative overflow-x-auto">
            @include('attendance.my.history-table', ['rows' => $historyRows->take($previewRows)])
        </div>
        @if($historyRows->count() > $previewRows)
            <div class="flex items-center justify-between border-t border-zinc-100 px-5 py-3 text-xs dark:border-zinc-800">
                <span class="text-zinc-500 dark:text-zinc-400">Latest {{ $previewRows }} of {{ $historyRows->count() }} {{ $filtersActive ? 'matching ' : '' }}days</span>
                <button type="button" @click="$flux.modal('history-full').show()" class="font-semibold text-orange-600 hover:text-orange-700">View full history</button>
            </div>
        @endif
    @endif
</section>

@if($historyRows->count() > $previewRows)
    <flux:modal name="history-full" flyout class="w-full md:w-[52rem]" data-history-full>
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Attendance History</flux:heading>
                <flux:subheading>{{ $mh['label'] }} · {{ $historyRows->count() }} days · click a day for its punches</flux:subheading>
            </div>
            <div class="relative -mx-2 overflow-x-auto">
                @include('attendance.my.history-table', ['rows' => $historyRows])
            </div>
        </div>
    </flux:modal>
@endif
