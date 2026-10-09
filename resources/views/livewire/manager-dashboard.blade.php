@php
    $user = auth()->user();
    $canApproveOt = $user->canApproveOt();
    $access = app(\App\Services\Help\RouteAccess::class);
    $link = fn (string $route): ?string => \Illuminate\Support\Facades\Route::has($route) && $access->allows($user, $route) ? route($route) : null;
    $isDepartmentView = (bool) ($scopeHeading ?? null);
    $title = $isDepartmentView ? 'Department View' : 'Team View';
    $subtitle = $isDepartmentView
        ? $scopeHeading.' — attendance, approvals and team performance at a glance.'
        : 'Attendance, approvals and team performance at a glance.';

    $teamSize = $teamAttendanceList->count();
    $pendingApprovals = $pendingLeaveCount + ($canApproveOt ? $pendingOtCount : 0);
    $formatWorked = fn (int $minutes): string => $minutes > 0 ? intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m' : '—';
    $shownRows = $teamAttendanceList->take(10);
    $attention = array_values(array_filter([
        ['label' => 'Leave approvals', 'count' => $pendingLeaveCount, 'href' => $link('time-off.team')],
        $canApproveOt ? ['label' => 'OT approvals', 'count' => $pendingOtCount, 'href' => $link('overtime.manage')] : null,
        ['label' => 'Missing check-outs', 'count' => $missingCheckoutCount, 'href' => $link('attendance.team'), 'tone' => 'red'],
        ['label' => 'Reviews due', 'count' => $reviewsPending, 'href' => $link('performance.team'), 'tone' => 'blue'],
    ]));
    $trendMax = max(1, collect($trend)->max(fn ($d) => $d['present'] + $d['absent']));
@endphp

<flux:main>
    <div class="mx-auto w-full max-w-[1400px] space-y-5">

        {{-- ── HEADER ── --}}
        <x-pulse.dashboard-header :title="$title" :subtitle="$subtitle" />

        {{-- ── ROW 1 — TODAY ── --}}
        <x-pulse.grid :cols="4">
            <x-pulse.kpi-card label="Present" :value="$presentCount" icon="check-circle" accent="emerald" :sub="'of '.$teamSize.' in the team'" />
            <x-pulse.kpi-card label="Late" :value="$lateCount" icon="clock" accent="amber" sub="late arrivals today" />
            <x-pulse.kpi-card label="Absent / Missing" :value="$absentCount + $missingCheckoutCount" icon="x-circle" accent="rose"
                :sub="$absentCount.' no clock-in · '.$missingCheckoutCount.' missing check-out'" />
            <x-pulse.kpi-card label="Pending approvals" :value="$pendingApprovals" icon="inbox-stack" accent="brand"
                :sub="$pendingLeaveCount.' leave'.($canApproveOt ? ' · '.$pendingOtCount.' OT' : '')" :href="$link('time-off.team')" />
        </x-pulse.grid>

        {{-- ── ROW 2 — ATTENDANCE + NEEDS ATTENTION ── --}}
        <x-pulse.grid :split="true" class="items-start">
            <x-pulse.card title="Team Attendance — Today" icon="users" flush>
                <x-slot:actions>
                    <a href="{{ route('attendance.team') }}" wire:navigate class="text-xs font-semibold text-brand-600 hover:text-brand-700 dark:text-brand-400">View all →</a>
                </x-slot:actions>

                <x-pulse.table :columns="['Employee', ['label' => 'Department', 'class' => 'hidden md:table-cell'], 'First in', 'Last out', 'Status', ['label' => 'Worked', 'class' => 'hidden lg:table-cell'], ['label' => 'Exception', 'class' => 'hidden md:table-cell']]" min="min-w-0">
                    @forelse($shownRows as $row)
                        <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/[0.03]">
                            <td class="whitespace-nowrap px-3 py-2.5 pl-5 font-medium text-zinc-900 dark:text-white">{{ $row['name'] }}</td>
                            <td class="hidden px-3 py-2.5 text-xs text-zinc-500 md:table-cell">{{ $row['department'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-xs tabular-nums text-zinc-600 dark:text-zinc-300">{{ $row['check_in'] ?? '—' }}</td>
                            <td class="px-3 py-2.5 text-xs tabular-nums text-zinc-600 dark:text-zinc-300">
                                @if($row['state'] === 'completed')
                                    {{ $row['check_out'] }}
                                @elseif(in_array($row['state'], ['working', 'on_break'], true))
                                    <span class="text-[11px] font-semibold text-brand-600" data-attendance-state="{{ $row['state'] }}">{{ $row['state'] === 'on_break' ? 'On break' : 'Live' }}</span>
                                @elseif($row['state'] === 'missing_checkout')
                                    <span class="text-[11px] font-semibold text-amber-600" data-attendance-state="missing_checkout">Missing</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="px-3 py-2.5">
                                @if(in_array($row['status'], ['weekly_off', 'weekly_off_worked'], true))
                                    <x-pulse.badge>{{ strtoupper($row['status'] === 'weekly_off' ? \App\Services\Attendance\WorkingDayResolver::WEEKLY_OFF_LABEL : \App\Services\Attendance\WorkingDayResolver::WORKED_WEEKLY_OFF_LABEL) }}</x-pulse.badge>
                                @elseif($row['status'] === 'not_in')
                                    <x-pulse.badge>Not in</x-pulse.badge>
                                @elseif($row['status'] === 'absent')
                                    <x-pulse.badge color="rose">Absent</x-pulse.badge>
                                @elseif($row['is_late'])
                                    <x-pulse.badge color="amber">Late</x-pulse.badge>
                                @else
                                    <x-pulse.badge color="emerald">On time</x-pulse.badge>
                                @endif
                            </td>
                            <td class="hidden px-3 py-2.5 text-xs tabular-nums text-zinc-600 dark:text-zinc-300 lg:table-cell">{{ $formatWorked($row['worked_minutes']) }}</td>
                            <td class="hidden px-3 py-2.5 pr-5 text-xs md:table-cell">
                                @if($row['state'] === 'missing_checkout')
                                    <x-pulse.badge color="amber">{{ strtoupper('Missing checkout') }}</x-pulse.badge>
                                @elseif($row['excess_break'])
                                    <x-pulse.badge color="amber">Long break</x-pulse.badge>
                                @else
                                    <span class="text-zinc-400">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-8"><x-pulse.empty-state icon="users" title="No team members yet" text="People who report to you appear here." /></td></tr>
                    @endforelse
                </x-pulse.table>

                @if($teamSize > $shownRows->count())
                    <div class="border-t border-zinc-100 px-5 py-2.5 text-xs text-zinc-500 dark:border-white/5">
                        Showing {{ $shownRows->count() }} of {{ $teamSize }} · <a href="{{ route('attendance.team') }}" wire:navigate class="font-semibold text-brand-600 hover:text-brand-700">View all</a>
                    </div>
                @endif
            </x-pulse.card>

            <x-pulse.card title="Needs Attention" icon="bell-alert">
                <x-pulse.attention-list :items="$attention" />

                @if($pendingLeaves->isNotEmpty())
                    <div class="mt-4 border-t border-zinc-100 pt-3 dark:border-white/5">
                        <p class="mb-2 text-[11px] font-semibold uppercase tracking-wider text-zinc-500">Leave waiting for you</p>
                        <div class="space-y-2">
                            @foreach($pendingLeaves->take(3) as $leave)
                                <div class="flex items-center justify-between gap-2 rounded-xl border border-zinc-100 px-3 py-2 dark:border-white/5">
                                    <div class="min-w-0">
                                        <div class="truncate text-[13px] font-semibold text-zinc-900 dark:text-white">{{ $leave->employee->user->name }}</div>
                                        <div class="truncate text-xs text-zinc-500">
                                            {{ $leave->leaveType?->name }} ·
                                            {{ \Carbon\Carbon::parse($leave->start_date)->format('d M') }}@if($leave->start_date != $leave->end_date) – {{ \Carbon\Carbon::parse($leave->end_date)->format('d M') }}@endif
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 gap-1.5">
                                        <button wire:click="quickApproveLeave({{ $leave->id }})"
                                            wire:confirm="Approve leave for {{ $leave->employee->user->name }}?"
                                            class="h-8 rounded-lg bg-emerald-600 px-3 text-xs font-semibold text-white transition hover:bg-emerald-700">Approve</button>
                                        <button wire:click="openRejectModal({{ $leave->id }})"
                                            class="h-8 rounded-lg bg-rose-50 px-3 text-xs font-semibold text-rose-700 transition hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-400">Reject</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        @if($pendingLeaveCount > 3)
                            <a href="{{ route('time-off.team') }}" wire:navigate class="mt-2 block text-xs font-semibold text-brand-600 hover:text-brand-700">See all {{ $pendingLeaveCount }} leave requests →</a>
                        @endif
                    </div>
                @endif
            </x-pulse.card>
        </x-pulse.grid>

        {{-- ── ROW 3 — TREND + LEAVE THIS WEEK ── --}}
        <x-pulse.grid :cols="2" class="items-start">
            <x-pulse.card title="Team Attendance Trend" :subtitle="count($trend) ? 'Last '.count($trend).' working days' : null" icon="chart-bar">
                @if(count($trend))
                    <div class="flex h-36 items-end gap-2" role="img" aria-label="Present, late and absent people for each of the last working days">
                        @foreach($trend as $day)
                            @php $ontime = max(0, $day['present'] - $day['late']); @endphp
                            <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1">
                                <div class="flex w-full max-w-9 flex-col-reverse overflow-hidden rounded-md" style="height: {{ round((($day['present'] + $day['absent']) / $trendMax) * 100) }}%"
                                     title="{{ $day['label'] }}: {{ $day['present'] }} present, {{ $day['late'] }} late, {{ $day['absent'] }} absent">
                                    <div class="bg-emerald-500" style="flex: {{ $ontime }} 1 0"></div>
                                    <div class="bg-amber-400" style="flex: {{ $day['late'] }} 1 0"></div>
                                    <div class="bg-rose-300" style="flex: {{ $day['absent'] }} 1 0"></div>
                                </div>
                                <span class="text-[11px] text-zinc-500">{{ $day['label'] }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-[11px] text-zinc-500">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-emerald-500"></span>On time</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-amber-400"></span>Late</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-sm bg-rose-300"></span>Absent</span>
                    </div>
                @else
                    <x-pulse.empty-state icon="chart-bar" title="No attendance yet" text="The trend appears once your team has working days on record." />
                @endif
            </x-pulse.card>

            <x-pulse.card title="Leave This Week" icon="calendar-days">
                @forelse($onLeaveThisWeek->take(5) as $away)
                    <div class="flex items-center justify-between gap-3 border-b border-zinc-100 py-2 text-[13px] last:border-0 dark:border-white/5">
                        <span class="min-w-0 truncate font-medium text-zinc-800 dark:text-zinc-100">{{ $away['name'] }}</span>
                        <span class="shrink-0 text-xs text-zinc-500">{{ $away['from'] === $away['to'] ? $away['from'] : $away['from'].' – '.$away['to'] }}</span>
                    </div>
                @empty
                    <x-pulse.empty-state icon="calendar-days" title="Nobody on approved leave" text="Approved leave this week appears here." />
                @endforelse

                @if($onLeaveThisWeek->count() > 5)
                    <div class="mt-2 border-t border-zinc-100 pt-2.5 text-xs text-zinc-500 dark:border-white/5">
                        Showing 5 of {{ $onLeaveThisWeek->count() }}@if($link('time-off.team')) · <a href="{{ $link('time-off.team') }}" wire:navigate class="font-semibold text-brand-600 hover:text-brand-700">View all</a>@endif
                    </div>
                @endif
            </x-pulse.card>
        </x-pulse.grid>

        {{-- ── ROW 4 — OT + REVIEW STATUS ── --}}
        <x-pulse.grid :cols="2" class="items-start">
            <x-pulse.card :title="'Team OT — '.now()->format('F')" icon="bolt">
                <div class="flex items-baseline gap-3">
                    <div class="text-[26px] font-bold leading-none tabular-nums text-zinc-900 dark:text-white">{{ rtrim(rtrim(number_format($teamOtHours, 2), '0'), '.') }}<span class="ml-1 text-sm font-medium text-zinc-400">h</span></div>
                    <div class="text-base font-semibold tabular-nums text-brand-600">₹{{ number_format($teamOtAmount) }}</div>
                </div>
                <p class="mt-1.5 text-xs text-zinc-500">Approved overtime recorded this month.</p>
            </x-pulse.card>

            <x-pulse.card title="Review Status" icon="star">
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5">
                        <div class="text-[22px] font-bold leading-none tabular-nums text-zinc-900 dark:text-white">{{ $reviewsSubmitted }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Completed this year</div>
                    </div>
                    <div class="rounded-xl bg-zinc-50 px-3 py-2.5 dark:bg-white/5">
                        <div class="text-[22px] font-bold leading-none tabular-nums text-zinc-900 dark:text-white">{{ $reviewsPending }}</div>
                        <div class="mt-1 text-xs text-zinc-500">Pending</div>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between gap-2">
                    <h4 class="text-[13px] font-semibold text-zinc-900 dark:text-white">Team KPI Scores @if($latestCycle) <span class="font-normal text-zinc-400">· {{ $latestCycle->name }}</span>@endif</h4>
                    @if($teamAvgKpi !== null)
                        <x-pulse.badge color="violet">avg {{ $teamAvgKpi }}</x-pulse.badge>
                    @endif
                </div>
                @if($teamKpis->isNotEmpty())
                    <div class="mt-2">
                        @foreach($teamKpis->take(5) as $sc)
                            <div class="flex items-center justify-between gap-3 border-b border-zinc-100 py-1.5 text-[13px] last:border-0 dark:border-white/5">
                                <span class="min-w-0 truncate text-zinc-700 dark:text-zinc-200">{{ $sc->employee?->user?->name ?? '—' }}</span>
                                <span class="flex shrink-0 items-center gap-2">
                                    @if($sc->grade)<x-pulse.badge>{{ $sc->grade }}</x-pulse.badge>@endif
                                    <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $sc->final_score }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @else
                    <x-pulse.empty-state class="mt-2" text="No active KPI review data." :href="$link('performance.team')" cta="View Performance" />
                @endif
            </x-pulse.card>
        </x-pulse.grid>
    </div>

    {{-- Reject Leave Modal (only interactive on the standalone ManagerDashboard component) --}}
    @if($showRejectModal ?? false)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showRejectModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showRejectModal', false)"></div>
            <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white p-6 shadow-xl ring ring-black/5 dark:bg-zinc-800 dark:ring-zinc-700">
                <button type="button" @click="$wire.set('showRejectModal', false)"
                    class="absolute right-4 top-4 text-zinc-400 transition-colors hover:text-zinc-600 dark:hover:text-zinc-200" aria-label="Close">
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
                        <button type="button" @click="$wire.set('showRejectModal', false)" class="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-600 transition-colors hover:bg-zinc-50 dark:border-zinc-600 dark:text-zinc-300 dark:hover:bg-zinc-700">Cancel</button>
                        <flux:button wire:click="quickRejectLeave" variant="danger">Reject Leave</flux:button>
                    </div>
                </div>
            </div>
        </div>
    @endif

</flux:main>
