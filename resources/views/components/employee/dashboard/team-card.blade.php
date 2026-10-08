@props(['team'])

{{-- MyTeamCard — reporting manager plus the employee's own placement. --}}
<x-employee.dashboard.card title="My Team" icon="users" {{ $attributes }}>
    <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Reporting manager</p>
    @if($team['manager'])
        <div class="mt-2 flex items-center gap-3">
            @if($team['manager']['photo_url'])
                <img src="{{ $team['manager']['photo_url'] }}" alt="" class="size-10 shrink-0 rounded-lg object-cover ring-1 ring-zinc-200 dark:ring-white/10">
            @else
                <span class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-sm font-semibold text-zinc-600 dark:bg-white/5 dark:text-zinc-300" aria-hidden="true">
                    {{ $team['manager']['initials'] }}
                </span>
            @endif
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $team['manager']['name'] }}</p>
                <p class="truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $team['manager']['designation'] ?? $team['manager']['email'] }}</p>
            </div>
            <flux:tooltip :content="'Email '.$team['manager']['name']">
                <a href="mailto:{{ $team['manager']['email'] }}" aria-label="Email {{ $team['manager']['name'] }}"
                   class="ms-auto flex size-8 shrink-0 items-center justify-center rounded-md text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-700 focus-visible:outline-2 focus-visible:outline-orange-500 dark:hover:bg-white/10 dark:hover:text-white">
                    <flux:icon.envelope class="size-4" />
                </a>
            </flux:tooltip>
        </div>
    @else
        <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No reporting manager assigned yet.</p>
    @endif

    <dl class="mt-4 grid grid-cols-2 gap-2">
        <div class="min-w-0 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/[0.03]">
            <dt class="text-[11px] text-zinc-500 dark:text-zinc-400">Department</dt>
            <dd class="mt-0.5 truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $team['department'] ?? 'Not set' }}</dd>
        </div>
        <div class="min-w-0 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/[0.03]">
            <dt class="text-[11px] text-zinc-500 dark:text-zinc-400">Designation</dt>
            <dd class="mt-0.5 truncate text-sm font-medium text-zinc-900 dark:text-white">{{ $team['designation'] ?? 'Not set' }}</dd>
        </div>
    </dl>

    {{-- Spec §5.4 team calendar: who in the department is away this week
         (name + dates only). --}}
    <div class="mt-4">
        <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">On leave this week</p>
        @php $awayAll = collect($team['on_leave_this_week'] ?? []); @endphp
        @forelse($awayAll->take(5) as $away)
            <div class="mt-1.5 flex items-center justify-between gap-2 text-sm">
                <span class="truncate text-zinc-700 dark:text-zinc-200">{{ $away['name'] }}</span>
                <span class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">{{ $away['dates'] }}</span>
            </div>
        @empty
            <p class="mt-1.5 text-sm text-zinc-500 dark:text-zinc-400">Everyone's in this week.</p>
        @endforelse
        @if($awayAll->count() > 5)
            <p class="mt-1.5 text-xs text-zinc-500 dark:text-zinc-400">+{{ $awayAll->count() - 5 }} more on leave this week</p>
        @endif
    </div>

    @if($team['org_chart_url'])
        <div class="pt-4">
            <a href="{{ $team['org_chart_url'] }}" wire:navigate
               class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm font-medium text-zinc-700 shadow-sm transition hover:bg-zinc-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500 dark:border-white/10 dark:bg-white/5 dark:text-zinc-200 dark:hover:bg-white/10">
                <flux:icon.user-group class="size-4" /> View Org Chart
            </a>
        </div>
    @endif
</x-employee.dashboard.card>
