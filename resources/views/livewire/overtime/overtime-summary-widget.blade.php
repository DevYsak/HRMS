<div class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-6">
    <div class="pulse-card text-center">
        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $otToday }}</p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">OT Today</p>
    </div>
    <div class="pulse-card text-center">
        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $otWeek }}</p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">OT This Week</p>
    </div>
    <div class="pulse-card text-center">
        <p class="text-2xl font-bold text-zinc-900 dark:text-white">{{ $otMonth }}</p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">OT This Month</p>
    </div>
    <div class="pulse-card text-center">
        <p class="text-2xl font-bold text-amber-600">{{ $pendingCount }}</p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Pending Approval</p>
    </div>
    <div class="pulse-card text-center">
        <p class="text-2xl font-bold text-emerald-600">{{ $approvedCount }}</p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Approved</p>
    </div>
    <div class="pulse-card text-center">
        <p class="text-2xl font-bold text-indigo-600">{{ $nexflowSynced }}</p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Nexflow Synced</p>
    </div>
</div>
