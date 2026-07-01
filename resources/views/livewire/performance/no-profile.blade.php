<flux:main class="p-4 md:p-6">
    <div class="mx-auto max-w-lg rounded-2xl border border-zinc-200 bg-white p-10 text-center shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-orange-50 dark:bg-orange-900/20">
            <flux:icon.trophy class="size-7 text-orange-500" />
        </div>
        <flux:heading size="lg">No personal performance data</flux:heading>
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
            This account isn't linked to an employee profile, so there's no personal review or scorecard to show.
        </p>
        @can('manage_review_cycles')
            <flux:button :href="route('performance.cycles')" wire:navigate variant="primary" class="mt-5">Manage Review Cycles</flux:button>
        @elsecan('manage_scorecards')
            <flux:button :href="route('performance.kpi-dashboard')" wire:navigate variant="primary" class="mt-5">Open KPI Dashboard</flux:button>
        @endcan
    </div>
</flux:main>
