<flux:main class="space-y-6 bg-zinc-50 p-6 dark:bg-zinc-950">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="pulse-page-title">OT Windows</h1>
            <p class="pulse-page-subtitle">Planning only: windows mark periods where overtime is expected. Employees can request overtime on any date; the manager decides.</p>
        </div>
        <flux:button href="{{ route('overtime.manage') }}" wire:navigate variant="ghost" icon="arrow-left">Manage OT Requests</flux:button>
    </div>

    <div @class([
        'rounded-xl border px-4 py-3 text-sm',
        'border-emerald-200 bg-emerald-50 text-emerald-800 dark:border-emerald-500/20 dark:bg-emerald-500/10 dark:text-emerald-300' => $openToday,
        'border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-300' => ! $openToday,
    ])>
        @if($openToday)
            Open today: <strong>{{ $openToday->title }}</strong> ({{ $openToday->starts_at->format('j M') }} – {{ $openToday->ends_at->format('j M Y') }}).
        @else
            No OT window is open today. Overtime requests are still accepted and go to the manager as usual.
        @endif
    </div>

    <div class="grid gap-6 lg:grid-cols-[22rem_1fr]">
        <form wire:submit="open" class="pulse-card space-y-4">
            <h2 class="text-sm font-bold text-zinc-800 dark:text-zinc-100">Open a window</h2>
            <flux:input wire:model="title" label="Title" placeholder="Q3 client deadline" />
            <flux:textarea wire:model="reason" label="Reason (optional)" rows="2" />
            <div class="grid grid-cols-2 gap-3">
                <flux:input wire:model="startsAt" type="date" label="From" />
                <flux:input wire:model="endsAt" type="date" label="To" />
            </div>
            <flux:button type="submit" variant="primary" icon="plus" class="w-full">Open window</flux:button>
        </form>

        <div class="pulse-card overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-[11px] font-bold uppercase tracking-wider text-zinc-400">
                        <th class="py-2 pe-3">Window</th>
                        <th class="py-2 pe-3">Dates</th>
                        <th class="py-2 pe-3">Opened by</th>
                        <th class="py-2 pe-3">Status</th>
                        <th class="py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse($windows as $window)
                        <tr wire:key="ot-window-{{ $window->id }}">
                            <td class="py-2 pe-3">
                                <div class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $window->title }}</div>
                                @if($window->reason)
                                    <div class="text-xs text-zinc-500">{{ $window->reason }}</div>
                                @endif
                            </td>
                            <td class="py-2 pe-3 whitespace-nowrap text-zinc-600 dark:text-zinc-300">{{ $window->starts_at->format('j M') }} – {{ $window->ends_at->format('j M Y') }}</td>
                            <td class="py-2 pe-3 text-zinc-600 dark:text-zinc-300">{{ $window->createdBy?->name ?? '—' }}</td>
                            <td class="py-2 pe-3">
                                @if($window->is_active)
                                    <flux:badge color="emerald" size="sm">Active</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm">Closed</flux:badge>
                                @endif
                            </td>
                            <td class="py-2 text-right">
                                @if($window->is_active)
                                    <flux:button wire:click="close({{ $window->id }})" wire:confirm="Close this OT window?" size="xs" variant="ghost">Close</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-6 text-center text-zinc-400">No OT windows yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</flux:main>
