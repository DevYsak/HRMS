<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">

    {{-- Header --}}
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Manager Dashboard</h1>
            <p class="pulse-page-subtitle">Your team overview for {{ now()->format('l, jS F Y') }}</p>
        </div>
        <div class="flex gap-2">
            <flux:button :href="route('time-off.team')" wire:navigate variant="ghost" icon="calendar-days">Leave Approvals</flux:button>
            <flux:button :href="route('overtime.manage')" wire:navigate variant="ghost" icon="clock">OT Approvals</flux:button>
        </div>
    </div>

    {{-- KPI Bar --}}
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Present Today</div>
            <div class="text-3xl font-black text-brand-600 mt-2">{{ $presentCount }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">Team members in</div>
        </div>
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Late Today</div>
            <div class="text-3xl font-black text-amber-500 mt-2">{{ $lateCount }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">Late arrivals flagged</div>
        </div>
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Absent Today</div>
            <div class="text-3xl font-black text-red-500 mt-2">{{ $absentCount }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">No clock-in recorded</div>
        </div>
        <div class="pulse-card text-center p-5">
            <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Reviews Pending</div>
            <div class="text-3xl font-black text-purple-500 mt-2">{{ $reviewsPending }}</div>
            <div class="text-[10px] text-zinc-400 mt-1">{{ $reviewsSubmitted }} submitted this year</div>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Team Attendance Table --}}
        <div class="pulse-card p-6 lg:col-span-2">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-4 flex items-center gap-2">
                <flux:icon.clock class="size-4 text-brand-500" /> Team Attendance — Today
            </h3>
            <div class="overflow-x-auto -mx-6">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="pb-2 pl-6 pr-4 text-left text-xs font-semibold uppercase text-zinc-400">Employee</th>
                            <th class="pb-2 pr-4 text-left text-xs font-semibold uppercase text-zinc-400">Dept</th>
                            <th class="pb-2 pr-4 text-left text-xs font-semibold uppercase text-zinc-400">In</th>
                            <th class="pb-2 pr-4 text-left text-xs font-semibold uppercase text-zinc-400">Out</th>
                            <th class="pb-2 pr-6 text-left text-xs font-semibold uppercase text-zinc-400">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                        @forelse($teamAttendanceList as $row)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                                <td class="py-3 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">{{ $row['name'] }}</td>
                                <td class="py-3 pr-4 text-zinc-500 text-xs">{{ $row['department'] ?? '—' }}</td>
                                <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">{{ $row['check_in'] ?? '—' }}</td>
                                <td class="py-3 pr-4 text-zinc-600 dark:text-zinc-300">{{ $row['check_out'] ?? '—' }}</td>
                                <td class="py-3 pr-6">
                                    @if($row['status'] === 'absent')
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-red-100 text-red-700">ABSENT</span>
                                    @elseif($row['is_late'])
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">LATE</span>
                                    @else
                                        <span class="text-[10px] font-bold px-2 py-0.5 rounded-full bg-green-100 text-green-700">ON TIME</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-10 text-center text-zinc-400">No team members assigned to you yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Approvals Panel --}}
        <div class="space-y-4">
            {{-- Pending Leaves --}}
            <div class="pulse-card p-5">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
                    <flux:icon.calendar-days class="size-4 text-amber-500" /> Pending Leave Approvals
                    @if($pendingLeaves->count())
                        <span class="ml-auto text-[10px] font-bold bg-amber-100 text-amber-700 px-2 py-0.5 rounded-full">{{ $pendingLeaves->count() }}</span>
                    @endif
                </h3>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    @forelse($pendingLeaves as $leave)
                        <div class="flex items-start justify-between gap-2 p-2 bg-zinc-50 dark:bg-zinc-900 rounded-lg">
                            <div>
                                <div class="text-xs font-semibold text-zinc-900 dark:text-white">{{ $leave->employee->user->name }}</div>
                                <div class="text-[11px] text-zinc-500">{{ $leave->leaveType?->name }} · {{ $leave->start_date }} → {{ $leave->end_date }}</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400 py-2">No pending leave requests.</p>
                    @endforelse
                </div>
                <flux:button :href="route('time-off.team')" wire:navigate size="sm" variant="ghost" class="w-full mt-3">Manage All →</flux:button>
            </div>

            {{-- Pending OT --}}
            <div class="pulse-card p-5">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white mb-3 flex items-center gap-2">
                    <flux:icon.clock class="size-4 text-purple-500" /> Pending OT Approvals
                    @if($pendingOt->count())
                        <span class="ml-auto text-[10px] font-bold bg-purple-100 text-purple-700 px-2 py-0.5 rounded-full">{{ $pendingOt->count() }}</span>
                    @endif
                </h3>
                <div class="space-y-2 max-h-48 overflow-y-auto">
                    @forelse($pendingOt as $ot)
                        <div class="flex items-start justify-between gap-2 p-2 bg-zinc-50 dark:bg-zinc-900 rounded-lg">
                            <div>
                                <div class="text-xs font-semibold text-zinc-900 dark:text-white">{{ $ot->employee->user->name }}</div>
                                <div class="text-[11px] text-zinc-500">{{ $ot->work_date }} · {{ $ot->requested_hours }}h</div>
                            </div>
                        </div>
                    @empty
                        <p class="text-xs text-zinc-400 py-2">No pending OT requests.</p>
                    @endforelse
                </div>
                <flux:button :href="route('overtime.manage')" wire:navigate size="sm" variant="ghost" class="w-full mt-3">Manage All →</flux:button>
            </div>
        </div>

    </div>

</flux:main>
