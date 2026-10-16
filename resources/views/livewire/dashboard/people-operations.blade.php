@php
    $me = auth()->user();
    $access = app(\App\Services\Help\RouteAccess::class);
    $link = fn (string $route, array $params = []): ?string => \Illuminate\Support\Facades\Route::has($route) && $access->allows($me, $route) ? route($route, $params) : null;
    $a = $overview['attendance'];
    $r = $overview['reviews'];
    $ap = $overview['approvals'];
    $hints = \App\Services\Attendance\TodayStatusService::HINTS;
    $deptLink = fn (?int $id) => $id && \Illuminate\Support\Facades\Route::has('departments.show') && $me->hasPermission('view_attendance') ? route('departments.show', $id) : null;
@endphp

{{-- People Operations Today: one department selector, every widget below follows it. --}}
<section class="space-y-5" data-people-operations>
    <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div class="min-w-0">
            <h2 class="text-lg font-bold text-zinc-900 dark:text-white">People Operations Today</h2>
            <x-pulse.scope-badge :scope="$scope" class="mt-1.5" />
        </div>
        <div class="w-full sm:w-64">
            <label for="po-department" class="mb-1 block text-xs font-semibold text-zinc-500">Department</label>
            <select id="po-department" wire:model.live="department" data-po-department
                class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100">
                @if($departments->count() > 1)<option value="">All Departments</option>@endif
                @foreach($departments as $d)
                    <option value="{{ $d->id }}">{{ $d->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Row 1 — attendance (TodayStatusService: weekly off / holiday / leave are never absent) --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6">
        <x-pulse.kpi-card label="Working headcount" :value="$a['total']" icon="users" :href="$link('employees.index')" :sub="$a['expected'].' due today'" />
        <x-pulse.kpi-card label="Present today" :value="$a['present']" icon="check-circle" accent="emerald" :href="$link('attendance.employees')" :sub="$a['working_now'].' in now'" />
        <x-pulse.kpi-card label="Late" :value="$a['late']" icon="clock" accent="amber" :href="$link('attendance.command-center')" sub="after shift start + grace" />
        <x-pulse.kpi-card label="Not checked in" :value="$a['no_checkin'] + $a['absent']" icon="user-minus" accent="rose" :href="$link('attendance.employees')"
            :sub="$a['day_off'] ? 'Weekly off — nobody due' : $a['absent'].' absent (shift over)'" />
        <x-pulse.kpi-card label="On Leave" :value="$a['on_leave']" icon="calendar-days" accent="blue" :href="$link('time-off.employees')" :sub="$a['weekly_off'] ? $a['weekly_off'].' on weekly off' : 'approved leave today'" />
        <x-pulse.kpi-card label="Missing checkout" :value="$a['missing_checkout']" icon="exclamation-triangle" accent="amber" :href="$link('attendance.command-center')" sub="past shift end + 1 h" />
    </div>

    {{-- Row 2 — what is waiting for this viewer, and reviews --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-3 2xl:grid-cols-6">
        <x-pulse.kpi-card label="Pending Leave" :value="$ap['leave'] ?? 0" icon="calendar" :href="$link('time-off.employees')" sub="your decision" />
        <x-pulse.kpi-card label="Pending OT" :value="$ap['overtime'] ?? 0" icon="bolt" accent="amber" :href="$link('overtime.manage')" :sub="$overview['ot_hours'].'h payable OT this month'" />
        <x-pulse.kpi-card label="Regularisation" :value="$ap['regularisation'] ?? 0" icon="pencil-square" :href="$link('attendance.command-center')" sub="HR review" />
        <x-pulse.kpi-card label="WFH Pending" :value="$ap['wfh'] ?? 0" icon="home" accent="blue" :href="$link('wfh.manage')" sub="your decision" />
        <x-pulse.kpi-card label="Reviews Due" :value="$r['pending']" icon="star" accent="indigo" :href="$link('performance.kpi-management')" :sub="$r['overdue'].' overdue'" />
        <x-pulse.kpi-card label="Profile / Data Issues" :value="$overview['data_issues']" icon="identification" accent="rose" :href="$link('employees.index')" sub="employees with gaps" />
    </div>

    {{-- Department Health (whole scope only) --}}
    @if($health->isNotEmpty())
        <x-pulse.card title="Department Health" subtitle="Today, and this month's approved OT and reviews" icon="building-office-2" flush>
            <x-pulse.table :columns="['Department', ['label' => 'Head', 'class' => 'hidden md:table-cell'], 'Employees', 'Present', 'Late', ['label' => 'On Leave', 'class' => 'hidden sm:table-cell'], ['label' => 'Missing Checkout', 'class' => 'hidden lg:table-cell'], 'Pending Approvals', ['label' => 'OT Hours', 'class' => 'hidden lg:table-cell'], ['label' => 'Reviews Due', 'class' => 'hidden md:table-cell'], ['label' => 'Avg Score', 'class' => 'hidden xl:table-cell'], ['label' => 'Data Issues', 'class' => 'hidden md:table-cell']]" min="min-w-0">
                @foreach($health as $row)
                    @php $href = $deptLink($row['department_id']); @endphp
                    <tr wire:key="dh-{{ $row['department_id'] ?? 'none' }}" data-department-health="{{ $row['department'] }}" class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-white/[0.03]">
                        <td class="px-3 py-2.5 pl-5 font-medium">
                            @if($href)<a href="{{ $href }}" wire:navigate class="text-brand-700 hover:underline dark:text-brand-300">{{ $row['department'] }}</a>@else{{ $row['department'] }}@endif
                        </td>
                        <td class="hidden px-3 py-2.5 text-xs text-zinc-500 md:table-cell">{{ $row['head'] ?? '—' }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $row['employees'] }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $row['present'] }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $row['late'] }}</td>
                        <td class="hidden px-3 py-2.5 tabular-nums sm:table-cell">{{ $row['on_leave'] }}</td>
                        <td class="hidden px-3 py-2.5 tabular-nums lg:table-cell">{{ $row['missing_checkout'] }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $row['pending_approvals'] }}</td>
                        <td class="hidden px-3 py-2.5 tabular-nums lg:table-cell">{{ $row['ot_hours'] }}h</td>
                        <td class="hidden px-3 py-2.5 tabular-nums md:table-cell">{{ $row['reviews_due'] }}</td>
                        <td class="hidden px-3 py-2.5 tabular-nums xl:table-cell">{{ $row['avg_score'] !== null ? $row['avg_score'].'%' : '—' }}</td>
                        <td class="hidden px-3 py-2.5 pr-5 tabular-nums md:table-cell">{{ $row['data_issues'] }}</td>
                    </tr>
                @endforeach
            </x-pulse.table>
        </x-pulse.card>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
        {{-- Performance snapshot --}}
        <x-pulse.card title="Performance Snapshot" :subtitle="$r['cycle']?->name ?? 'No review cycle running'" icon="star">
            <div class="grid grid-cols-3 gap-3 text-center sm:grid-cols-5">
                @foreach(['not_started' => 'Not Started', 'self_review_pending' => 'Self Review', 'manager_review_pending' => 'Manager Review', 'completed' => 'Completed', 'overdue' => 'Overdue'] as $key => $label)
                    <div class="rounded-xl bg-zinc-50 px-2 py-2.5 dark:bg-white/5" data-perf-state="{{ $key }}">
                        <div @class(['text-xl font-bold tabular-nums', 'text-rose-600' => $key === 'overdue' && $r[$key] > 0, 'text-zinc-900 dark:text-white' => ! ($key === 'overdue' && $r[$key] > 0)])>{{ $r[$key] }}</div>
                        <div class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-zinc-500">{{ $label }}</div>
                    </div>
                @endforeach
            </div>
            @if($performanceByDepartment->count() > 1)
                <x-pulse.table class="mt-4" :columns="['Department', 'Employees', 'Completed', 'Pending', 'Overdue', 'Avg']" min="min-w-0">
                    @foreach($performanceByDepartment as $row)
                        <tr><td class="px-3 py-2 pl-5">{{ $row['department'] }}</td><td class="px-3 py-2 tabular-nums">{{ $row['total'] }}</td><td class="px-3 py-2 tabular-nums">{{ $row['completed'] }}</td><td class="px-3 py-2 tabular-nums">{{ $row['pending'] }}</td><td class="px-3 py-2 tabular-nums">{{ $row['overdue'] }}</td><td class="px-3 py-2 pr-5 tabular-nums">{{ $row['avg_score'] ?? '—' }}</td></tr>
                    @endforeach
                </x-pulse.table>
            @endif
        </x-pulse.card>

        {{-- Data quality: scope and reporting depend on clean structure --}}
        <x-pulse.card title="Data Quality" subtitle="Problems are reported, never auto-filled" icon="identification" flush>
            <ul class="divide-y divide-zinc-100 dark:divide-white/5" data-data-quality>
                @foreach($quality['checks'] as $key => $check)
                    <li class="flex items-center justify-between gap-3 px-5 py-2.5 text-sm">
                        <span class="text-zinc-700 dark:text-zinc-200">{{ $check['label'] }}</span>
                        @if($check['count'] > 0 && $link('employees.index'))
                            <a href="{{ route('employees.index', ['incomplete' => 1]) }}" wire:navigate class="rounded-full bg-rose-50 px-2 py-0.5 text-xs font-bold tabular-nums text-rose-700 hover:bg-rose-100 dark:bg-rose-500/10 dark:text-rose-300">{{ $check['count'] }}</a>
                        @else
                            <span class="text-xs font-semibold tabular-nums text-zinc-400">{{ $check['count'] }}</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </x-pulse.card>
    </div>
</section>
