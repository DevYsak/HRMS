<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

        {{-- =====================================================
             Page Header
             ===================================================== --}}
        <div>
            <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">
                Hi, {{ Str::of(auth()->user()->name)->explode(' ')->first() }} 👋
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                This is your HR report so far
            </p>
        </div>

        {{-- =====================================================
             Row 1 — Stat Cards (left) + Team Performance Chart (right)
             ===================================================== --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            {{-- Stat Cards 2×2 --}}
            <div class="grid grid-cols-2 gap-4 lg:col-span-1">

                {{-- Total Employees --}}
                <div class="pulse-card flex flex-col gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                        <svg class="size-5 text-zinc-500 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="pulse-stat__value">{{ number_format($employeeCount) }}</div>
                        <span class="pulse-stat__trend-up mt-1">
                            ↑ +25.5%
                        </span>
                    </div>
                    <div class="pulse-stat__label">Total Employees</div>
                </div>

                {{-- Job Applicants --}}
                <div class="pulse-card flex flex-col gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-zinc-100 dark:bg-zinc-800">
                        <svg class="size-5 text-zinc-500 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 00.75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 00-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0112 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 01-.673-.38m0 0A2.18 2.18 0 013 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 013.413-.387m7.5 0V5.25A2.25 2.25 0 0013.5 3h-3a2.25 2.25 0 00-2.25 2.25v.894m7.5 0a48.667 48.667 0 00-7.5 0M12 12.75h.008v.008H12v-.008z" />
                        </svg>
                    </div>
                    <div>
                        <div class="pulse-stat__value">{{ number_format($candidateCount) }}</div>
                        <span class="pulse-stat__trend-up mt-1">
                            ↑ +4.10%
                        </span>
                    </div>
                    <div class="pulse-stat__label">Job Applicants</div>
                </div>

                {{-- New Employees --}}
                <div class="pulse-card flex flex-col gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-emerald-50 dark:bg-emerald-900/20">
                        <svg class="size-5 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v6m3-3H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="pulse-stat__value">{{ number_format($newEmployees) }}</div>
                        <span class="pulse-stat__trend-up mt-1">
                            ↑ +5.1%
                        </span>
                    </div>
                    <div class="pulse-stat__label">New Employees</div>
                </div>

                {{-- Resigned Employees --}}
                <div class="pulse-card flex flex-col gap-3">
                    <div class="flex size-10 items-center justify-center rounded-xl bg-red-50 dark:bg-red-900/20">
                        <svg class="size-5 text-red-500 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12H9m12 0a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <div>
                        <div class="pulse-stat__value">{{ number_format($resignedCount) }}</div>
                        <span class="pulse-stat__trend-down mt-1">
                            ↓ +25.5%
                        </span>
                    </div>
                    <div class="pulse-stat__label">Resigned Employees</div>
                </div>

            </div>

            {{-- Team Performance — SVG Line Chart --}}
            <div class="pulse-card lg:col-span-2">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">Team Performance</h3>
                    <button class="flex items-center gap-1.5 rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800">
                        Last 7 months
                        <svg class="size-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </button>
                </div>

                {{-- Legend --}}
                <div class="flex items-center gap-5 mb-4 text-xs text-zinc-500 dark:text-zinc-400">
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-brand-600"></span> Project Team
                    </span>
                    <span class="flex items-center gap-1.5">
                        <span class="size-2.5 rounded-full bg-amber-400"></span> Product Team
                    </span>
                </div>

                {{-- SVG Line Chart —  Y-axis 30k–60k, 7 months --}}
                <div class="relative">
                    {{-- Y-axis labels --}}
                    <div class="absolute -left-1 top-0 flex flex-col justify-between h-36 text-xs text-zinc-400 dark:text-zinc-600 text-right pe-2">
                        <span>60k</span>
                        <span>50k</span>
                        <span>40k</span>
                        <span>30k</span>
                    </div>
                    <div class="ms-8">
                        <svg viewBox="0 0 560 144" class="w-full h-36 overflow-visible" preserveAspectRatio="none">
                            {{-- Grid lines --}}
                            <line x1="0" y1="0"   x2="560" y2="0"   stroke="#e5e7eb" stroke-width="1"/>
                            <line x1="0" y1="48"  x2="560" y2="48"  stroke="#e5e7eb" stroke-width="1"/>
                            <line x1="0" y1="96"  x2="560" y2="96"  stroke="#e5e7eb" stroke-width="1"/>
                            <line x1="0" y1="144" x2="560" y2="144" stroke="#e5e7eb" stroke-width="1"/>

                            {{-- Project Team line (green) — values mapped 30k-60k to y 144-0 --}}
                            {{-- Points: Jan=48k, Feb=42k, Mar=50k, Apr=55k, May=52k, Jun=58k, Jul=54k --}}
                            <polyline
                                fill="none"
                                stroke="#1DB77A"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                points="0,96 93,144 187,48 280,0 373,48 467,0 560,48"
                            />
                            {{-- Dot at Apr highlight --}}
                            <circle cx="280" cy="0" r="4" fill="#1DB77A" />
                            <circle cx="280" cy="0" r="7" fill="#1DB77A" fill-opacity="0.2" />

                            {{-- Product Team line (amber) --}}
                            {{-- Points: Jan=42k, Feb=48k, Mar=44k, Apr=52k, May=46k, Jun=50k, Jul=56k --}}
                            <polyline
                                fill="none"
                                stroke="#f59e0b"
                                stroke-width="2.5"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                points="0,144 93,48 187,120 280,24 373,96 467,48 560,0"
                            />
                            <circle cx="280" cy="24" r="4" fill="#f59e0b" />
                        </svg>

                        {{-- X-axis month labels --}}
                        <div class="flex justify-between mt-2 text-xs text-zinc-400 dark:text-zinc-600">
                            @foreach(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul'] as $m)
                                <span>{{ $m }}</span>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

        </div>

        {{-- =====================================================
             Row 2 — Employees Table (left) + Donut Chart (right)
             ===================================================== --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

            {{-- Employees Table --}}
            <div class="pulse-card lg:col-span-2">
                <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">Employees</h3>

                    <div class="flex items-center gap-2">
                        {{-- Search --}}
                        <div class="relative">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                            <input type="text" placeholder="Search employee" class="h-8 rounded-lg border border-zinc-200 bg-white pl-9 pr-3 text-sm text-zinc-800 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100" />
                        </div>
                        {{-- Filters --}}
                        <select class="h-8 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            <option>All Offices</option>
                        </select>
                        <select class="h-8 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            <option>All Job Titles</option>
                        </select>
                        <select class="h-8 rounded-lg border border-zinc-200 bg-white px-2 text-xs text-zinc-600 focus:outline-none dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                            <option>All Status</option>
                        </select>
                    </div>
                </div>

                <div class="overflow-x-auto -mx-6">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-100 dark:border-zinc-800">
                                <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Employee Name</th>
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Job Title</th>
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Line Manager</th>
                                <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Department</th>
                                <th class="pb-3 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                            @foreach($recentEmployees as $emp)
                                <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors">
                                    <td class="py-3.5 pl-6 pr-4">
                                        <div class="flex items-center gap-3">
                                            <div class="flex size-8 shrink-0 items-center justify-center rounded-full bg-brand-600 text-xs font-bold text-white">
                                                {{ strtoupper(substr($emp->user->name, 0, 1)) }}
                                            </div>
                                            <div>
                                                <div class="font-medium text-zinc-900 dark:text-white">{{ $emp->user->name }}</div>
                                                <div class="text-xs text-zinc-400">{{ $emp->user->email }}</div>
                                            </div>
                                        </div>
                                    </td>
                                    <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-300">{{ $emp->jobTitle?->title ?? 'N/A' }}</td>
                                    <td class="py-3.5 pr-4 font-medium text-brand-700 dark:text-brand-400">{{ $emp->manager?->name ?? '-' }}</td>
                                    <td class="py-3.5 pr-4 text-zinc-600 dark:text-zinc-300">{{ $emp->department?->name ?? 'N/A' }}</td>
                                    <td class="py-3.5 pr-6">
                                        @if($emp->status->value === 'active')
                                            <span class="badge-active">ACTIVE</span>
                                        @elseif($emp->status->value === 'onboarding')
                                            <span class="badge-onboarding">ON BOARDING</span>
                                        @elseif($emp->status->value === 'probation')
                                            <span class="badge-probation">PROBATION</span>
                                        @elseif($emp->status->value === 'on-leave')
                                            <span class="badge-on-leave">ON LEAVE</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                    <flux:link :href="route('employees.index')" wire:navigate class="text-sm font-medium text-brand-600 hover:text-brand-700">
                        View all employees →
                    </flux:link>
                </div>
            </div>

            {{-- Total Employee Distribution — Donut --}}
            <div class="pulse-card">
                <div class="flex items-center justify-between mb-5">
                    <h3 class="font-semibold text-zinc-900 dark:text-white">Total Employee</h3>
                    <span class="text-xs font-medium text-zinc-400">All Time</span>
                </div>

                {{-- SVG Donut --}}
                <div class="flex items-center justify-center py-3">
                    <div class="relative size-40">
                        <svg viewBox="0 0 36 36" class="size-full -rotate-90">
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f3f4f6" stroke-width="3.2"/>
                            {{-- Others 59% --}}
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#1DB77A" stroke-width="3.2"
                                    stroke-dasharray="59 41" stroke-dashoffset="0"/>
                            {{-- Onboarding 22% --}}
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#f59e0b" stroke-width="3.2"
                                    stroke-dasharray="22 78" stroke-dashoffset="-59"/>
                            {{-- Offboarding 19% --}}
                            <circle cx="18" cy="18" r="15.9" fill="none" stroke="#3b82f6" stroke-width="3.2"
                                    stroke-dasharray="19 81" stroke-dashoffset="-81"/>
                        </svg>
                        <div class="absolute inset-0 flex flex-col items-center justify-center">
                            <span class="text-2xl font-bold text-zinc-900 dark:text-white">121</span>
                            <span class="text-xs text-zinc-400">Total Emp.</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-3 mt-3">
                    @foreach([
                        ['Others',      71, '#1DB77A'],
                        ['Onboarding',  27, '#f59e0b'],
                        ['Offboarding', 23, '#3b82f6'],
                    ] as [$label, $count, $color])
                        <div class="flex items-center justify-between">
                            <span class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                                <span class="size-2.5 shrink-0 rounded-full" style="background-color: {{ $color }}"></span>
                                {{ $label }}
                            </span>
                            <span class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $count }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

        </div>

    </flux:main>
