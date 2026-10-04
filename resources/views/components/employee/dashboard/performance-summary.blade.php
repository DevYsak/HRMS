@props(['performance'])

@php
    $goalPct = $performance['goals_total'] > 0
        ? (int) round($performance['goals_completed'] / $performance['goals_total'] * 100)
        : null;
@endphp

{{-- PerformanceSummary — latest cycle, the employee's own scorecard, review
     status and goal completion. --}}
<x-employee.dashboard.card title="Performance" icon="star" :href="route('performance.dashboard')" cta="View performance" {{ $attributes }}>
    @if(! $performance['cycle'])
        <x-employee.dashboard.empty-state icon="star" title="No active review cycle"
            text="When HR opens a review cycle, your rating and review status appear here." />
        @if($goalPct !== null)
            <p class="text-center text-xs text-zinc-500 dark:text-zinc-400">{{ $performance['goals_completed'] }} of {{ $performance['goals_total'] }} goals completed</p>
        @endif
    @else
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Current cycle</p>
                <p class="mt-0.5 truncate text-base font-semibold text-zinc-900 dark:text-white">{{ $performance['cycle']['name'] }}</p>
            </div>
            <span class="shrink-0 rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-white/5 dark:text-zinc-300">{{ $performance['cycle']['status'] }}</span>
        </div>

        <div class="mt-4 flex items-end gap-3">
            @if($performance['score'] !== null)
                <p class="text-3xl font-semibold tabular-nums tracking-tight text-zinc-900 dark:text-white">{{ \App\Services\EmployeeDashboardService::formatDays($performance['score']) }}</p>
                <p class="pb-1 text-xs text-zinc-500 dark:text-zinc-400">score</p>
                @if($performance['grade'])
                    <span class="mb-1 ms-auto rounded-md bg-orange-50 px-2 py-0.5 text-sm font-semibold text-orange-700 ring-1 ring-inset ring-orange-600/15 dark:bg-orange-500/10 dark:text-orange-300">Grade {{ $performance['grade'] }}</span>
                @endif
            @else
                <p class="text-sm text-zinc-500 dark:text-zinc-400">Not rated yet for this cycle.</p>
            @endif
        </div>

        <dl class="mt-4 divide-y divide-zinc-100 text-sm dark:divide-white/[0.06]">
            <div class="flex items-center justify-between py-1.5">
                <dt class="text-zinc-500 dark:text-zinc-400">Review status</dt>
                <dd class="text-zinc-800 dark:text-zinc-200">{{ $performance['review_status'] ?? 'Not started' }}</dd>
            </div>
            <div class="py-1.5">
                <div class="flex items-center justify-between">
                    <dt class="text-zinc-500 dark:text-zinc-400">Goals</dt>
                    <dd class="tabular-nums text-zinc-800 dark:text-zinc-200">
                        {{ $goalPct !== null ? $performance['goals_completed'].' of '.$performance['goals_total'].' done' : 'No goals set' }}
                    </dd>
                </div>
                @if($goalPct !== null)
                    <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10" role="progressbar"
                         aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $goalPct }}" aria-label="Goal completion">
                        <div class="h-full rounded-full bg-orange-500" style="width: {{ $goalPct }}%"></div>
                    </div>
                @endif
            </div>
        </dl>
    @endif
</x-employee.dashboard.card>
