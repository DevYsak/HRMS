{{--
    Attendance History — the selected month's days (monthHistory, canonical),
    filtered, sorted and paged on the server (FiltersAttendanceHistory). The
    form edits drafts; "Apply Filters" validates and applies them together
    (no page reload) and the applied state lives in the query string. Export
    downloads exactly the filtered rows. "Why?" explains the engine's decision.
--}}
@php
    $page = $this->historyPageData;
    $filtersActive = $this->historyFiltersActive();
    [$minDate, $maxDate] = $this->historyDateBounds();
    $tone = [
        'green' => 'bg-emerald-50 text-emerald-700 ring-emerald-200/70 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20',
        'amber' => 'bg-amber-50 text-amber-700 ring-amber-200/70 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20',
        'red' => 'bg-rose-50 text-rose-700 ring-rose-200/70 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20',
        'blue' => 'bg-sky-50 text-sky-700 ring-sky-200/70 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20',
        'sky' => 'bg-cyan-50 text-cyan-700 ring-cyan-200/70 dark:bg-cyan-500/10 dark:text-cyan-300 dark:ring-cyan-500/20',
        'violet' => 'bg-violet-50 text-violet-700 ring-violet-200/70 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20',
        'rose' => 'bg-fuchsia-50 text-fuchsia-700 ring-fuchsia-200/70 dark:bg-fuchsia-500/10 dark:text-fuchsia-300 dark:ring-fuchsia-500/20',
        'muted' => 'bg-zinc-100 text-zinc-600 ring-zinc-200/70 dark:bg-white/[0.05] dark:text-slate-300 dark:ring-white/10',
    ];
    $todayKey = today()->toDateString();
    $label = 'mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-slate-400';
    $error = 'mt-1 text-xs font-medium text-rose-600 dark:text-rose-400';
    // Page links: first, last and two either side of the current page.
    $pageLinks = collect(range(1, $page['pages']))->filter(fn ($p) => $p === 1 || $p === $page['pages'] || abs($p - $page['page']) <= 1)->values();
@endphp

<section class="{{ $ui['card'] }}" aria-labelledby="history-title" data-history>
    <div class="flex flex-wrap items-start justify-between gap-3 px-5 pt-5">
        <div class="flex items-start gap-3">
            <span class="{{ $ui['tile'] }} bg-orange-100 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300"><flux:icon.calendar-days class="size-5" /></span>
            <div>
                <h2 id="history-title" class="{{ $ui['title'] }}">Attendance History</h2>
                <p class="{{ $ui['sub'] }} mt-0.5">View and filter your daily attendance records · {{ $mh['label'] }}</p>
            </div>
        </div>
        <button type="button" wire:click="exportHistory" class="{{ $ui['btn'] }}" data-history-export>
            <flux:icon.arrow-down-tray class="size-4" /> Export{{ $filtersActive ? ' filtered' : '' }}
        </button>
    </div>

    {{-- Filters: drafts until Apply (Enter also applies). --}}
    <form wire:submit="applyHistoryFilters" class="mx-5 mt-4 grid grid-cols-1 gap-3 rounded-2xl bg-zinc-50/80 p-3 ring-1 ring-inset ring-zinc-100 sm:grid-cols-2 lg:grid-cols-[repeat(4,minmax(0,1fr))_auto] lg:items-start dark:bg-white/[0.02] dark:ring-white/[0.05]" aria-label="Filter attendance history" data-history-filters novalidate>
        <div class="min-w-0">
            <label for="history-from" class="{{ $label }}">From</label>
            <input id="history-from" type="date" wire:model="filterFrom" min="{{ $minDate }}" max="{{ $maxDate }}" class="{{ $ui['field'] }} @error('filterFrom') !border-rose-400 @enderror" data-filter="from" @error('filterFrom') aria-invalid="true" aria-describedby="history-from-error" @enderror>
            @error('filterFrom')<p id="history-from-error" class="{{ $error }}" data-filter-error="from">{{ $message }}</p>@enderror
        </div>
        <div class="min-w-0">
            <label for="history-to" class="{{ $label }}">To</label>
            <input id="history-to" type="date" wire:model="filterTo" min="{{ $minDate }}" max="{{ $maxDate }}" class="{{ $ui['field'] }} @error('filterTo') !border-rose-400 @enderror" data-filter="to" @error('filterTo') aria-invalid="true" aria-describedby="history-to-error" @enderror>
            @error('filterTo')<p id="history-to-error" class="{{ $error }}" data-filter-error="to">{{ $message }}</p>@enderror
        </div>
        <div class="min-w-0">
            <label for="history-status" class="{{ $label }}">Status</label>
            <select id="history-status" wire:model="filterStatus" class="{{ $ui['field'] }}" data-filter="status">
                <option value="">All Statuses</option>
                @foreach(\App\Livewire\Attendance\AttendanceTracker::HISTORY_STATUSES as $value => $text)
                    <option value="{{ $value }}">{{ $text }}</option>
                @endforeach
            </select>
            @error('filterStatus')<p class="{{ $error }}">{{ $message }}</p>@enderror
        </div>
        <div class="min-w-0">
            <label for="history-mode" class="{{ $label }}">Mode</label>
            <select id="history-mode" wire:model="filterMode" class="{{ $ui['field'] }}" data-filter="mode">
                <option value="">All Modes</option>
                @foreach(\App\Livewire\Attendance\AttendanceTracker::HISTORY_MODES as $mode)
                    <option value="{{ $mode }}">{{ \App\Enums\AttendanceMode::tryFromValue($mode)->label() }}</option>
                @endforeach
            </select>
            @error('filterMode')<p class="{{ $error }}">{{ $message }}</p>@enderror
        </div>
        <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-1 lg:pt-[22px]">
            <button type="button" wire:click="clearHistoryFilters" class="{{ $ui['btn'] }} flex-1 lg:flex-none" data-filter="clear"><flux:icon.arrow-path class="size-4" /> Clear</button>
            <button type="submit" class="{{ $ui['btnPrimary'] }} flex-1 lg:flex-none" data-filter="apply" wire:loading.attr="disabled" wire:target="applyHistoryFilters">
                <flux:icon.funnel class="size-4" /> Apply Filters
            </button>
        </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-2 px-5 pb-2 pt-3 text-xs text-zinc-500 dark:text-slate-400" aria-live="polite">
        <span data-history-count>
            @if($page['total'] > 0)
                Showing {{ $page['from'] }}–{{ $page['to'] }} of {{ $page['total'] }} {{ \Illuminate\Support\Str::plural('record', $page['total']) }}{{ $filtersActive ? ' (filtered from '.$page['month_total'].')' : '' }}
            @else
                0 records
            @endif
        </span>
        <span class="hidden sm:inline">Click a day for its punches</span>
    </div>

    <div class="relative" wire:loading.class="opacity-60" wire:target="applyHistoryFilters,clearHistoryFilters,setHistoryPage,historyPerPage,sortHistory">
        @if($page['month_total'] === 0)
            <p class="px-5 pb-8 pt-2 text-sm text-zinc-500 dark:text-slate-400">No attendance days in {{ $mh['label'] }} yet.</p>
        @elseif($page['total'] === 0)
            <div class="mx-5 mb-5 flex flex-col items-center rounded-2xl border border-dashed border-zinc-200 px-6 py-10 text-center dark:border-white/10" data-history-no-match>
                <span class="flex size-12 items-center justify-center rounded-2xl bg-orange-50 text-orange-500 dark:bg-orange-500/10 dark:text-orange-300"><flux:icon.funnel class="size-6" /></span>
                <p class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white">No attendance records match these filters.</p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-slate-400">Try a wider date range or a different status or mode.</p>
                <button type="button" wire:click="clearHistoryFilters" class="{{ $ui['btn'] }} mt-4">Clear Filters</button>
            </div>
        @else
            <div class="overflow-x-auto">
                @include('attendance.my.history-table', ['rows' => $page['rows']])
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-100 px-5 py-3 dark:border-white/[0.06]" data-history-pagination>
                <label class="flex items-center gap-2 text-xs text-zinc-500 dark:text-slate-400">Rows per page
                    <select wire:model.live="historyPerPage" class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-xs text-zinc-700 dark:border-white/10 dark:bg-[#0D1526] dark:text-slate-200 dark:[color-scheme:dark]" data-history-per-page>
                        @foreach(\App\Livewire\Attendance\AttendanceTracker::HISTORY_PAGE_SIZES as $size)
                            <option value="{{ $size }}">{{ $size }}</option>
                        @endforeach
                    </select>
                </label>
                @if($page['pages'] > 1)
                    <nav class="flex items-center gap-1" aria-label="History pages">
                        <button type="button" wire:click="setHistoryPage({{ $page['page'] - 1 }})" @disabled($page['page'] <= 1) class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:text-slate-400 dark:hover:bg-white/10" aria-label="Previous page"><flux:icon.chevron-left class="size-4" /></button>
                        @foreach($pageLinks as $i => $p)
                            @if($i > 0 && $p - $pageLinks[$i - 1] > 1)<span class="px-1 text-xs text-zinc-400">…</span>@endif
                            <button type="button" wire:click="setHistoryPage({{ $p }})" @class([
                                'min-w-8 rounded-lg px-2 py-1 text-xs font-semibold tabular-nums transition',
                                'bg-orange-500 text-white shadow-sm' => $p === $page['page'],
                                'text-zinc-600 hover:bg-zinc-100 dark:text-slate-300 dark:hover:bg-white/10' => $p !== $page['page'],
                            ]) @if($p === $page['page']) aria-current="page" @endif data-history-page="{{ $p }}">{{ $p }}</button>
                        @endforeach
                        <button type="button" wire:click="setHistoryPage({{ $page['page'] + 1 }})" @disabled($page['page'] >= $page['pages']) class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:text-slate-400 dark:hover:bg-white/10" aria-label="Next page"><flux:icon.chevron-right class="size-4" /></button>
                    </nav>
                @endif
            </div>
        @endif
    </div>
</section>
