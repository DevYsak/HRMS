<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-zinc-900 dark:text-white">Onboarding</h1>
            <p class="text-sm text-zinc-500 mt-1">New joiners in the last 90 days and their checklist progress.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('settings.onboarding-templates') }}" wire:navigate
                class="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-white hover:border-orange-300 hover:text-orange-600 px-4 py-2.5 text-sm font-bold text-zinc-700 shadow-sm transition-colors dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 0 0 2.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 0 0-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25ZM6.75 12h.008v.008H6.75V12Zm0 3h.008v.008H6.75V15Zm0 3h.008v.008H6.75V18Z"/></svg>
                Templates
            </a>
            <a href="{{ route('employees.create') }}" wire:navigate
                class="inline-flex items-center gap-2 rounded-xl bg-brand-600 hover:bg-brand-700 px-4 py-2.5 text-sm font-bold text-white shadow transition-colors">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Add Employee
            </a>
        </div>
    </div>

    {{-- Tab Navigation --}}
    <div class="flex border-b border-zinc-200 dark:border-zinc-800 gap-1">
        <button wire:click="$set('activeTab', 'employees')"
            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors -mb-px
                {{ $activeTab === 'employees' ? 'border-brand-600 text-brand-600 dark:text-brand-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            Employees
        </button>
        <button wire:click="$set('activeTab', 'analytics')"
            class="px-4 py-2.5 text-sm font-semibold border-b-2 transition-colors -mb-px
                {{ $activeTab === 'analytics' ? 'border-brand-600 text-brand-600 dark:text-brand-400' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
            Analytics
        </button>
    </div>

    @if($activeTab === 'employees')
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

    @else
        {{-- Analytics Tab --}}
        @if($analytics)
            {{-- Stat Cards --}}
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-4">
                @php
                    $statCards = [
                        ['label' => 'Total Tasks',  'value' => $analytics->total,       'color' => 'text-zinc-900 dark:text-white', 'bg' => ''],
                        ['label' => 'Completed',     'value' => $analytics->completed,   'color' => 'text-emerald-600',              'bg' => 'bg-emerald-50 dark:bg-emerald-950/30'],
                        ['label' => 'Pending',       'value' => $analytics->pending,     'color' => 'text-zinc-600 dark:text-zinc-300', 'bg' => ''],
                        ['label' => 'In Progress',   'value' => $analytics->in_progress, 'color' => 'text-blue-600',                 'bg' => 'bg-blue-50 dark:bg-blue-950/30'],
                        ['label' => 'Overdue',       'value' => $analytics->overdue,     'color' => 'text-red-600',                  'bg' => 'bg-red-50 dark:bg-red-950/30'],
                        ['label' => 'Blocked',       'value' => $analytics->blocked,     'color' => 'text-orange-600',               'bg' => 'bg-orange-50 dark:bg-orange-950/30'],
                    ];
                @endphp
                @foreach($statCards as $card)
                    <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 px-5 py-4 {{ $card['bg'] }}">
                        <p class="text-xs font-bold uppercase tracking-wide text-zinc-400 mb-1">{{ $card['label'] }}</p>
                        <p class="text-3xl font-black {{ $card['color'] }}">{{ $card['value'] ?? 0 }}</p>
                    </div>
                @endforeach
            </div>

            {{-- Completion Rate --}}
            @php $rate = $analytics->total > 0 ? round(($analytics->completed / $analytics->total) * 100) : 0; @endphp
            <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 px-6 py-5">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-wide">Overall Completion Rate</h3>
                    <span class="text-2xl font-black {{ $rate === 100 ? 'text-emerald-600' : 'text-brand-600' }}">{{ $rate }}%</span>
                </div>
                <div class="w-full bg-zinc-100 dark:bg-zinc-800 rounded-full h-3 overflow-hidden">
                    <div class="h-full rounded-full transition-all duration-700 {{ $rate === 100 ? 'bg-emerald-500' : 'bg-brand-600' }}"
                         style="width: {{ $rate }}%"></div>
                </div>
                <div class="flex justify-between mt-2 text-xs text-zinc-400">
                    <span>{{ $analytics->completed }} of {{ $analytics->total }} tasks completed across all employees</span>
                    @if($analytics->overdue > 0)
                        <span class="text-red-500 font-bold">{{ $analytics->overdue }} overdue</span>
                    @endif
                </div>
            </div>

            {{-- Owner Breakdown --}}
            @if($ownerBreakdown && $ownerBreakdown->isNotEmpty())
                <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                    <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-wide">Tasks by Owner Role</h3>
                    </div>
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-50 dark:border-zinc-800">
                                <th class="py-3 pl-6 pr-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Owner Role</th>
                                <th class="py-3 px-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Total</th>
                                <th class="py-3 px-4 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Completed</th>
                                <th class="py-3 pr-6 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Progress</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @foreach($ownerBreakdown as $row)
                                @php $rowPct = $row->total > 0 ? round(($row->completed / $row->total) * 100) : 0; @endphp
                                <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20 transition-colors">
                                    <td class="py-3 pl-6 pr-4">
                                        <span class="inline-flex items-center rounded-md bg-zinc-100 dark:bg-zinc-800 px-2.5 py-1 text-xs font-bold text-zinc-600 dark:text-zinc-300 uppercase">
                                            {{ $row->owner_role ?? 'Unassigned' }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-zinc-600 dark:text-zinc-400 font-mono">{{ $row->total }}</td>
                                    <td class="py-3 px-4 font-mono {{ $row->completed == $row->total ? 'text-emerald-600 font-bold' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $row->completed }}</td>
                                    <td class="py-3 pr-6">
                                        <div class="flex items-center gap-3">
                                            <div class="h-1.5 w-32 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                                <div class="h-full rounded-full {{ $rowPct === 100 ? 'bg-emerald-500' : 'bg-brand-600' }}"
                                                     style="width: {{ $rowPct }}%"></div>
                                            </div>
                                            <span class="text-xs font-bold {{ $rowPct === 100 ? 'text-emerald-600' : 'text-zinc-500' }}">{{ $rowPct }}%</span>
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <div class="rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900 py-16 text-center">
                <svg class="mx-auto mb-3 size-10 text-zinc-200 dark:text-zinc-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>
                <p class="text-sm font-semibold text-zinc-400">No onboarding tasks found</p>
                <p class="mt-1 text-xs text-zinc-300">Add employees to start tracking onboarding progress</p>
            </div>
        @endif
    @endif

</flux:main>
