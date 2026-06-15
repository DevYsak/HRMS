<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">

    @php
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
        $timeContext = $hour < 12 ? 'Your team is ready — time to lead.' : ($hour < 17 ? 'Afternoon check-in — team overview below.' : 'End of day — review pending approvals.');
    @endphp
    {{-- ── HEADER ── --}}
    <div class="pulse-hero">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>
        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-5">
            <div>
                <div class="flex items-center gap-2.5 mb-3">
                    <div class="inline-flex items-center gap-1.5 px-3 py-1 bg-white/10 border border-white/10 rounded-full">
                        @if($hour < 12)
                            <flux:icon.sun class="size-3 text-amber-300" />
                            <span class="text-[11px] font-semibold text-white/70">Morning</span>
                        @elseif($hour < 17)
                            <flux:icon.sun class="size-3 text-orange-300" />
                            <span class="text-[11px] font-semibold text-white/70">Afternoon</span>
                        @else
                            <flux:icon.moon class="size-3 text-blue-300" />
                            <span class="text-[11px] font-semibold text-white/70">Evening</span>
                        @endif
                    </div>
                    <span class="text-white/40 text-xs">{{ now()->format('l, d F Y') }}</span>
                </div>
                <h1 class="text-3xl font-black text-white tracking-tight">{{ $greeting }}, {{ $firstName }}</h1>
                <p class="text-white/55 text-sm mt-1.5">{{ $timeContext }}</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('time-off.team') }}" wire:navigate
                   class="flex items-center gap-2 px-4 py-2.5 bg-white/10 hover:bg-white/20 border border-white/10 text-white rounded-xl text-sm font-semibold transition-all">
                    <flux:icon.calendar-days class="size-4" />
                    Leave Approvals
                    @if($pendingLeaves->count())
                        <span class="px-1.5 py-0.5 bg-amber-500 text-white text-[9px] font-black rounded-full">{{ $pendingLeaves->count() }}</span>
                    @endif
                </a>
                <a href="{{ route('overtime.manage') }}" wire:navigate
                   class="flex items-center gap-2 px-4 py-2.5 bg-white text-orange-700 hover:bg-orange-50 rounded-xl text-sm font-bold transition-all shadow-sm">
                    <flux:icon.clock class="size-4" />
                    OT Approvals
                    @if($pendingOt->count())
                        <span class="px-1.5 py-0.5 bg-white/25 text-white text-[9px] font-black rounded-full">{{ $pendingOt->count() }}</span>
                    @endif
                </a>
            </div>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

        {{-- ── KPI ROW ── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Present</span>
                    <div class="size-8 rounded-xl bg-brand-50 dark:bg-brand-950/30 flex items-center justify-center">
                        <flux:icon.check-circle class="size-4 text-brand-600" />
                    </div>
                </div>
                <div class="text-4xl font-black text-brand-600">{{ $presentCount }}</div>
                <div class="text-[10px] text-zinc-400 mt-1">clocked in today</div>
            </div>
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Late</span>
                    <div class="size-8 rounded-xl bg-amber-50 dark:bg-amber-950/30 flex items-center justify-center">
                        <flux:icon.clock class="size-4 text-amber-500" />
                    </div>
                </div>
                <div class="text-4xl font-black text-amber-500">{{ $lateCount }}</div>
                <div class="text-[10px] text-zinc-400 mt-1">late arrivals flagged</div>
            </div>
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Absent</span>
                    <div class="size-8 rounded-xl bg-rose-50 dark:bg-rose-950/30 flex items-center justify-center">
                        <flux:icon.x-circle class="size-4 text-rose-500" />
                    </div>
                </div>
                <div class="text-4xl font-black text-rose-500">{{ $absentCount }}</div>
                <div class="text-[10px] text-zinc-400 mt-1">no clock-in recorded</div>
            </div>
            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 p-5 shadow-sm">
                <div class="flex items-center justify-between mb-3">
                    <span class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Reviews</span>
                    <div class="size-8 rounded-xl bg-purple-50 dark:bg-purple-950/30 flex items-center justify-center">
                        <flux:icon.star class="size-4 text-purple-500" />
                    </div>
                </div>
                <div class="text-4xl font-black text-purple-500">{{ $reviewsPending }}</div>
                <div class="text-[10px] text-zinc-400 mt-1">{{ $reviewsSubmitted }} submitted this cycle</div>
            </div>
        </div>

        {{-- ── TEAM KPI SCORES ── --}}
        <div class="pulse-card">
            <div class="mb-4 flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <flux:icon.chart-bar class="size-4 text-purple-500" />
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Team KPI Scores</h3>
                    @if($latestCycle)<span class="text-[10px] text-zinc-400">{{ $latestCycle->name }}</span>@endif
                </div>
                @if($teamAvgKpi !== null)
                    <span class="rounded-full bg-purple-100 px-2.5 py-0.5 text-xs font-black text-purple-700 dark:bg-purple-950/40 dark:text-purple-400">avg {{ $teamAvgKpi }}</span>
                @endif
            </div>
            @if($teamKpis->isNotEmpty())
                <div class="space-y-2">
                    @foreach($teamKpis as $sc)
                        <div class="flex items-center justify-between gap-3 border-b border-zinc-50 pb-2 last:border-0 last:pb-0 dark:border-zinc-800/60">
                            <span class="truncate text-sm text-zinc-700 dark:text-zinc-200">{{ $sc->employee?->user?->name ?? '—' }}</span>
                            <div class="flex items-center gap-2">
                                @if($sc->grade)<span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">{{ $sc->grade }}</span>@endif
                                <span class="text-sm font-bold text-zinc-900 dark:text-white">{{ $sc->final_score }}</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="py-6 text-center text-xs text-zinc-400">No KPI scores for your team in the latest cycle.</p>
            @endif
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-5">

            {{-- ── TEAM ATTENDANCE TABLE ── --}}
            <div class="lg:col-span-2 bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                        <flux:icon.users class="size-4 text-brand-500" /> Team Attendance — Today
                    </h3>
                    <a href="{{ route('attendance.team') }}" wire:navigate class="text-xs text-brand-600 hover:text-brand-700 font-bold">Full view →</a>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-zinc-50 dark:bg-zinc-950 border-b border-zinc-100 dark:border-zinc-800">
                            <th class="py-3 px-6 text-left text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Employee</th>
                            <th class="py-3 px-4 text-left text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Dept</th>
                            <th class="py-3 px-4 text-left text-[10px] font-bold text-zinc-400 uppercase tracking-widest">In</th>
                            <th class="py-3 px-4 text-left text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Out</th>
                            <th class="py-3 px-6 text-left text-[10px] font-bold text-zinc-400 uppercase tracking-widest">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/50">
                        @forelse($teamAttendanceList as $row)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors">
                                <td class="py-3.5 px-6 font-semibold text-zinc-900 dark:text-white">{{ $row['name'] }}</td>
                                <td class="py-3.5 px-4 text-zinc-400 text-xs">{{ $row['department'] ?? '—' }}</td>
                                <td class="py-3.5 px-4 text-zinc-600 dark:text-zinc-300 font-mono text-xs">{{ $row['check_in'] ?? '—' }}</td>
                                <td class="py-3.5 px-4 text-zinc-600 dark:text-zinc-300 font-mono text-xs">
                                    @if($row['check_out'])
                                        {{ $row['check_out'] }}
                                    @elseif($row['check_in'])
                                        <span class="text-brand-600 animate-pulse text-[10px] font-bold">LIVE</span>
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="py-3.5 px-6">
                                    @if($row['status'] === 'absent')
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-rose-50 dark:bg-rose-950/40 text-rose-700 dark:text-rose-400">ABSENT</span>
                                    @elseif($row['is_late'])
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-amber-50 dark:bg-amber-950/40 text-amber-700 dark:text-amber-400">LATE</span>
                                    @else
                                        <span class="px-2 py-0.5 rounded text-[9px] font-bold bg-emerald-50 dark:bg-emerald-950/40 text-emerald-700 dark:text-emerald-400">ON TIME</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="py-14 text-center text-zinc-400 text-sm">No team members assigned to you yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- ── APPROVALS SIDEBAR ── --}}
            <div class="space-y-4">

                {{-- Pending Leaves --}}
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="text-xs font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.calendar-days class="size-3.5 text-amber-500" /> Leave Approvals
                        </h3>
                        @if($pendingLeaves->count())
                            <span class="px-2 py-0.5 bg-amber-100 dark:bg-amber-900/40 text-amber-700 dark:text-amber-400 text-[10px] font-black rounded-full">{{ $pendingLeaves->count() }} PENDING</span>
                        @endif
                    </div>
                    <div class="p-4 space-y-2.5 max-h-64 overflow-y-auto">
                        @forelse($pendingLeaves as $leave)
                            <div class="p-3 bg-amber-50 dark:bg-amber-950/20 border border-amber-100 dark:border-amber-900/40 rounded-xl">
                                <div class="font-semibold text-xs text-zinc-900 dark:text-white">{{ $leave->employee->user->name }}</div>
                                <div class="text-[10px] text-zinc-500 mt-0.5">
                                    {{ $leave->leaveType?->name }} Â·
                                    {{ \Carbon\Carbon::parse($leave->start_date)->format('d M') }}
                                    @if($leave->start_date != $leave->end_date)
                                        – {{ \Carbon\Carbon::parse($leave->end_date)->format('d M') }}
                                    @endif
                                    @if($leave->reason)
                                        Â· <span class="italic">{{ \Illuminate\Support\Str::limit($leave->reason, 30) }}</span>
                                    @endif
                                </div>
                                <div class="flex gap-1.5 mt-2">
                                    <button wire:click="quickApproveLeave({{ $leave->id }})"
                                        wire:confirm="Approve leave for {{ $leave->employee->user->name }}?"
                                        class="flex-1 py-1 text-[10px] font-bold bg-emerald-600 hover:bg-emerald-700 text-white rounded-lg transition-colors">
                                        Approve
                                    </button>
                                    <button wire:click="openRejectModal({{ $leave->id }})"
                                        class="flex-1 py-1 text-[10px] font-bold bg-rose-100 hover:bg-rose-200 dark:bg-rose-900/30 dark:hover:bg-rose-900/50 text-rose-700 dark:text-rose-400 rounded-lg transition-colors">
                                        Reject
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="py-6 text-center text-zinc-400 text-xs">All clear — no pending leaves</div>
                        @endforelse
                    </div>
                    <div class="px-4 pb-4">
                        <a href="{{ route('time-off.team') }}" wire:navigate
                           class="block w-full py-2 text-center text-xs font-bold text-brand-600 hover:text-brand-700 bg-brand-50 dark:bg-brand-950/30 hover:bg-brand-100 rounded-xl transition-colors">
                            Manage All Leaves →
                        </a>
                    </div>
                </div>

                {{-- Pending OT --}}
                <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-200 dark:border-zinc-800 shadow-sm overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="text-xs font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                            <flux:icon.clock class="size-3.5 text-purple-500" /> OT Approvals
                        </h3>
                        @if($pendingOt->count())
                            <span class="px-2 py-0.5 bg-purple-100 dark:bg-purple-900/40 text-purple-700 dark:text-purple-400 text-[10px] font-black rounded-full">{{ $pendingOt->count() }} PENDING</span>
                        @endif
                    </div>
                    <div class="p-4 space-y-2.5 max-h-52 overflow-y-auto">
                        @forelse($pendingOt as $ot)
                            <div class="p-3 bg-purple-50 dark:bg-purple-950/20 border border-purple-100 dark:border-purple-900/40 rounded-xl">
                                <div class="font-semibold text-xs text-zinc-900 dark:text-white">{{ $ot->employee->user->name }}</div>
                                <div class="text-[10px] text-zinc-500 mt-0.5">
                                    {{ \Carbon\Carbon::parse($ot->work_date)->format('d M, D') }} Â· {{ $ot->requested_hours }}h estimated
                                </div>
                            </div>
                        @empty
                            <div class="py-6 text-center text-zinc-400 text-xs">All clear — no pending OT</div>
                        @endforelse
                    </div>
                    <div class="px-4 pb-4">
                        <a href="{{ route('overtime.manage') }}" wire:navigate
                           class="block w-full py-2 text-center text-xs font-bold text-brand-600 hover:text-brand-700 bg-brand-50 dark:bg-brand-950/30 hover:bg-brand-100 rounded-xl transition-colors">
                            Manage All OT →
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>


    {{-- Reject Leave Modal (only interactive on the standalone ManagerDashboard component) --}}
    @if($showRejectModal ?? false)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showRejectModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showRejectModal', false)"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showRejectModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Reject Leave Request</flux:heading>
                <flux:subheading>Please provide a reason for the rejection.</flux:subheading>
            </div>
            <flux:textarea wire:model="rejectComment" label="Reason for Rejection" placeholder="Enter reason..." rows="3" />
            @error('rejectComment') <div class="text-xs text-rose-600">{{ $message }}</div> @enderror
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" @click="$wire.set('showRejectModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                <flux:button wire:click="quickRejectLeave" variant="danger">Reject Leave</flux:button>
            </div>
        </div>
    
            </div>
        </div>
    @endif

</flux:main>
