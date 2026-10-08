{{--
    Attendance History — the selected month, newest first, from monthHistory.
    Upcoming dates are left out. The card shows the latest 8 days; "View full
    history" opens the whole month in a right-side drawer. A day with punches
    opens its detail (punch-detail); "Why?" explains the engine's decision.
--}}
@php
    $historyRows = collect($mh['rows'] ?? [])
        // Future dates are left out whatever their type (a future weekly off or holiday too).
        ->reject(fn (array $r) => $r['date'] > today()->toDateString() || $r['status'] === 'Not employed')
        ->reverse()->values();
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

    @if($historyRows->isEmpty())
        <p class="px-5 pb-6 pt-2 text-sm text-zinc-500 dark:text-zinc-400">No attendance days in {{ $mh['label'] }} yet.</p>
    @else
        <div class="relative overflow-x-auto">
            @include('attendance.my.history-table', ['rows' => $historyRows->take($previewRows)])
        </div>
        @if($historyRows->count() > $previewRows)
            <div class="flex items-center justify-between border-t border-zinc-100 px-5 py-3 text-xs dark:border-zinc-800">
                <span class="text-zinc-500 dark:text-zinc-400">Latest {{ $previewRows }} of {{ $historyRows->count() }} days</span>
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
