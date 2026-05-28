<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-zinc-900 dark:text-white">Onboarding</h1>
            <p class="text-sm text-zinc-500 mt-1">New joiners in the last 90 days and their checklist progress.</p>
        </div>
        <a href="{{ route('employees.create') }}" wire:navigate
            class="inline-flex items-center gap-2 rounded-xl bg-brand-600 hover:bg-brand-700 px-4 py-2.5 text-sm font-bold text-white shadow transition-colors">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
            Add Employee
        </a>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="relative">
            <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search employee…"
                class="h-9 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm focus:ring-2 focus:ring-orange-500 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
        </div>
        <div class="flex rounded-lg border border-zinc-200 overflow-hidden text-sm dark:border-zinc-700">
            <button wire:click="$set('filter','active')"
                class="px-4 py-1.5 font-semibold transition-colors {{ $filter === 'active' ? 'bg-brand-600 text-white' : 'bg-white text-zinc-500 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400' }}">
                Recent (90 days)
            </button>
            <button wire:click="$set('filter','all')"
                class="px-4 py-1.5 font-semibold transition-colors {{ $filter === 'all' ? 'bg-brand-600 text-white' : 'bg-white text-zinc-500 hover:bg-zinc-50 dark:bg-zinc-800 dark:text-zinc-400' }}">
                All Employees
            </button>
        </div>
    </div>

    {{-- Table --}}
    <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-100 dark:border-zinc-800">
                    <th class="py-3 pl-6 pr-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Employee</th>
                    <th class="py-3 px-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Department</th>
                    <th class="py-3 px-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Joining Date</th>
                    <th class="py-3 px-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Checklist Progress</th>
                    <th class="py-3 pr-6 text-right text-xs font-bold uppercase tracking-wide text-zinc-400">Action</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                @forelse($employees as $emp)
                    @php
                        $stats = $taskStats[$emp->id] ?? null;
                        $total = $stats?->total ?? 0;
                        $completed = $stats?->completed ?? 0;
                        $pct = $total > 0 ? round(($completed / $total) * 100) : 0;
                        $daysIn = $emp->joining_date ? (int) $emp->joining_date->diffInDays(now()) : null;
                    @endphp
                    <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors">
                        <td class="py-4 pl-6 pr-4">
                            <div class="flex items-center gap-3">
                                <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                                    {{ strtoupper(substr($emp->user->name, 0, 1)) }}
                                </div>
                                <div>
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $emp->user->name }}</div>
                                    <div class="text-xs text-zinc-400">{{ $emp->employee_id }}</div>
                                </div>
                            </div>
                        </td>
                        <td class="py-4 px-4 text-zinc-600 dark:text-zinc-400">{{ $emp->department?->name ?? '—' }}</td>
                        <td class="py-4 px-4">
                            <div class="text-zinc-700 dark:text-zinc-300">{{ $emp->joining_date?->format('d M Y') ?? '—' }}</div>
                            @if($daysIn !== null)
                                <div class="text-xs text-zinc-400">Day {{ $daysIn + 1 }}</div>
                            @endif
                        </td>
                        <td class="py-4 px-4">
                            @if($total > 0)
                                <div class="flex items-center gap-3">
                                    <div class="h-2 w-28 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                        <div class="h-full rounded-full transition-all {{ $pct === 100 ? 'bg-emerald-500' : 'bg-brand-600' }}"
                                            style="width: {{ $pct }}%"></div>
                                    </div>
                                    <span class="text-xs font-bold {{ $pct === 100 ? 'text-emerald-600' : 'text-zinc-500' }}">
                                        {{ $completed }}/{{ $total }} ({{ $pct }}%)
                                    </span>
                                </div>
                            @else
                                <span class="text-xs text-zinc-400 italic">No tasks yet</span>
                            @endif
                        </td>
                        <td class="py-4 pr-6 text-right">
                            <a href="{{ route('employees.onboarding', $emp->id) }}" wire:navigate
                                class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-bold text-zinc-700 hover:border-orange-300 hover:text-orange-600 transition-colors dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 0 2-2h2a2 2 0 0 0 2 2"/></svg>
                                View Checklist
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-16 text-center">
                            <svg class="mx-auto mb-3 size-10 text-zinc-200 dark:text-zinc-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>
                            <p class="text-sm font-semibold text-zinc-400">No new joiners in the last 90 days</p>
                            <p class="mt-1 text-xs text-zinc-300">Switch to "All Employees" to see everyone</p>
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        @if($employees->hasPages())
            <div class="border-t border-zinc-100 px-6 py-3 dark:border-zinc-800">
                {{ $employees->links() }}
            </div>
        @endif
    </div>

</flux:main>
