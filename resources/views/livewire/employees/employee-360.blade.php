@php
    $dd = app(\App\Support\DateDisplay::class);
    $fmtMinutes = fn (?int $minutes) => $minutes === null ? '—' : $dd->time(today()->addMinutes($minutes));
    $hm = fn (int $minutes) => intdiv($minutes, 60).'h '.str_pad((string) ($minutes % 60), 2, '0', STR_PAD_LEFT).'m';
    $toneDot = ['green' => 'bg-emerald-500', 'amber' => 'bg-amber-500', 'sky' => 'bg-sky-500', 'red' => 'bg-rose-500', 'violet' => 'bg-violet-500', 'teal' => 'bg-teal-500', 'zinc' => 'bg-zinc-400'];
    $toneSoft = ['green' => 'bg-emerald-50 text-emerald-700 ring-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/20', 'amber' => 'bg-amber-50 text-amber-700 ring-amber-100 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/20', 'sky' => 'bg-sky-50 text-sky-700 ring-sky-100 dark:bg-sky-500/10 dark:text-sky-300 dark:ring-sky-500/20', 'red' => 'bg-rose-50 text-rose-700 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20', 'violet' => 'bg-violet-50 text-violet-700 ring-violet-100 dark:bg-violet-500/10 dark:text-violet-300 dark:ring-violet-500/20', 'teal' => 'bg-teal-50 text-teal-700 ring-teal-100 dark:bg-teal-500/10 dark:text-teal-300 dark:ring-teal-500/20', 'zinc' => 'bg-zinc-50 text-zinc-600 ring-zinc-100 dark:bg-white/5 dark:text-zinc-300 dark:ring-white/10'];
    $kpiAccent = ['green' => 'emerald', 'amber' => 'amber', 'violet' => 'indigo', 'sky' => 'blue', 'red' => 'rose', 'zinc' => 'brand', 'orange' => 'brand'];
    $kpiIcon = ['present' => 'calendar-days', 'late' => 'clock', 'leave' => 'sun', 'performance' => 'chart-bar', 'warnings' => 'exclamation-triangle', 'overtime' => 'bolt'];
    $statusColor = ['present' => 'emerald', 'late' => 'amber', 'absent' => 'rose', 'weekly_off' => 'zinc', 'leave' => 'violet', 'holiday' => 'blue'];
    $leaveColor = ['pending' => 'amber', 'pending_hr' => 'amber', 'more_info_requested' => 'blue', 'approved' => 'emerald', 'rejected' => 'rose', 'cancelled' => 'zinc'];
    $statusBadge = ['green' => 'emerald', 'lime' => 'emerald', 'sky' => 'blue', 'amber' => 'amber', 'orange' => 'brand', 'red' => 'rose', 'fuchsia' => 'violet', 'blue' => 'blue', 'zinc' => 'zinc'];
    $reportUrl = route('employees.360.report', ['employee' => $employee->id, 'from' => $from, 'to' => $to]);
@endphp

{{-- flux:main places the page in the app shell's main area, beside the sidebar (every page needs it). --}}
<flux:main class="bg-zinc-50 p-4 sm:p-6 dark:bg-zinc-950">
<div class="mx-auto w-full max-w-[1400px] min-w-0 space-y-5" data-employee-360>
    {{-- ── Breadcrumb + scope ── --}}
    <div class="flex flex-wrap items-center justify-between gap-2">
        <nav class="flex min-w-0 items-center gap-1.5 text-xs font-medium text-zinc-500" aria-label="Breadcrumb">
            <a href="{{ route('employees.index') }}" wire:navigate class="hover:text-orange-600">People</a>
            <flux:icon.chevron-right class="size-3 shrink-0" />
            <span class="truncate text-zinc-800 dark:text-zinc-200">Employee 360°</span>
        </nav>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-50 px-2.5 py-1 text-[11px] font-semibold text-orange-700 ring-1 ring-orange-100 dark:bg-orange-500/10 dark:text-orange-300 dark:ring-orange-500/20" data-scope-label>
            <flux:icon.eye class="size-3.5" /> {{ $scopeLabel }}
        </span>
    </div>

    {{-- ── Global filters ── --}}
    <div class="ui-surface grid grid-cols-1 gap-3 p-3 sm:grid-cols-2 lg:grid-cols-[minmax(0,1.5fr)_minmax(0,1.6fr)_repeat(3,minmax(0,1fr))_auto] lg:items-end" data-360-filters>
        <label class="min-w-0">
            <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Employee</span>
            <select x-on:change="$wire.selectEmployee($event.target.value)" data-employee-selector
                class="h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-800 focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100">
                @foreach($employeeOptions as $id => $label)
                    <option value="{{ $id }}" @selected($id === $employee->id)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <div class="grid min-w-0 grid-cols-2 gap-2">
            <label class="min-w-0">
                <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500">From</span>
                <input type="date" wire:model.live.debounce.400ms="from" class="h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-2 text-sm dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100" aria-label="From date">
            </label>
            <label class="min-w-0">
                <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500">To</span>
                <input type="date" wire:model.live.debounce.400ms="to" class="h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-2 text-sm dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100" aria-label="To date">
            </label>
        </div>
        <label class="min-w-0">
            <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Department</span>
            <select wire:model.live="department" class="h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 text-sm dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100">
                <option value="">All in my scope</option>
                @foreach($departmentOptions as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </select>
        </label>
        <div class="grid min-w-0 grid-cols-2 gap-2 lg:contents">
            <label class="min-w-0">
                <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Attendance</span>
                <select wire:model.live="attendanceStatus" @disabled(! $attendance) class="h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 text-sm disabled:opacity-50 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100">
                    <option value="">All statuses</option>
                    @foreach(\App\Services\People\Employee360Service::ATTENDANCE_STATUSES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
            <label class="min-w-0">
                <span class="mb-1 block text-[11px] font-semibold uppercase tracking-wide text-zinc-500">Leave</span>
                <select wire:model.live="leaveStatus" @disabled(! $leave) class="h-10 w-full min-w-0 rounded-lg border border-zinc-200 bg-white px-3 text-sm disabled:opacity-50 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100">
                    @foreach(\App\Services\People\Employee360Service::LEAVE_TABS as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <flux:button size="sm" variant="ghost" wire:click="thisMonth" class="lg:mb-1">This month</flux:button>
    </div>

    {{-- ── Identity header ── --}}
    <section class="ui-surface relative overflow-hidden p-4 sm:p-5" data-360-header>
        <div class="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-orange-400 via-orange-500 to-amber-400"></div>
        <div class="flex flex-col gap-4 xl:flex-row xl:items-start xl:justify-between">
            <div class="flex min-w-0 items-start gap-4">
                @if($identity['avatar'])
                    <img src="{{ $identity['avatar'] }}" alt="" class="size-16 shrink-0 rounded-2xl object-cover ring-4 ring-orange-50 sm:size-20 dark:ring-orange-500/10">
                @else
                    <div class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-orange-100 text-xl font-bold text-orange-700 sm:size-20 dark:bg-orange-500/15 dark:text-orange-300">{{ $identity['initials'] }}</div>
                @endif
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <h1 class="truncate text-xl font-bold tracking-tight text-zinc-900 sm:text-2xl dark:text-white">{{ $identity['name'] }}</h1>
                        <x-pulse.badge :color="$statusBadge[$identity['status_color']] ?? 'zinc'">{{ $identity['status'] }}</x-pulse.badge>
                    </div>
                    <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-300">{{ $identity['designation'] ?? 'No designation' }} · {{ $identity['department'] ?? 'No department' }}</p>
                    <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-2 text-xs sm:grid-cols-3 min-[1800px]:grid-cols-6">
                        @foreach([
                            'Employee ID' => $identity['code'] ?: '—',
                            'Joined' => $identity['joining_date'] ? $dd->date($identity['joining_date']) : '—',
                            'Manager' => $identity['manager'] ?? '—',
                            'Shift' => $identity['shift'] ?? '—',
                            'Office' => $identity['office'] ?? '—',
                            'Work mode' => $identity['work_mode'] ?? '—',
                        ] as $term => $value)
                            <div class="min-w-0">
                                <dt class="text-[10px] font-semibold uppercase tracking-wide text-zinc-400">{{ $term }}</dt>
                                <dd class="truncate font-medium text-zinc-800 dark:text-zinc-100" title="{{ $value }}">{{ $value }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            </div>

            <div class="flex shrink-0 flex-wrap items-center gap-2" data-360-actions>
                @if($identity['email'])
                    <flux:button size="sm" icon="envelope" :href="'mailto:'.$identity['email']">Send message</flux:button>
                @endif
                @if($can['profile'])
                    <flux:button size="sm" icon="user" :href="route('employees.profile', $employee)" wire:navigate>View profile</flux:button>
                @endif
                @if($can['edit'])
                    <flux:button size="sm" icon="pencil-square" :href="route('employees.edit', $employee)" wire:navigate>Edit</flux:button>
                @endif
                <flux:dropdown position="bottom" align="end">
                    <flux:button size="sm" icon="ellipsis-horizontal" aria-label="More actions">More</flux:button>
                    <flux:menu class="w-60">
                        @if($can['attendance_page'])
                            <flux:menu.item icon="calendar-days" :href="route('attendance.employees', ['employee' => $employee->id])">Full attendance</flux:menu.item>
                        @endif
                        @if($can['leave_detail'])
                            <flux:menu.item icon="sun" :href="route('time-off.leave-management.employee', $employee)">Leave history</flux:menu.item>
                        @endif
                        @if($performance && $performance['url'])
                            <flux:menu.item icon="chart-bar" :href="$performance['url']">Performance review</flux:menu.item>
                        @endif
                        @if($can['warnings_page'])
                            <flux:menu.item icon="exclamation-triangle" :href="route('performance.warnings.manage')">Warnings &amp; conduct</flux:menu.item>
                        @endif
                        @if($activity && $activity['full'])
                            <flux:menu.item icon="clipboard-document-list" :href="route('settings.audit-log')">Activity log</flux:menu.item>
                        @endif
                        <flux:menu.item icon="arrow-down-tray" :href="$reportUrl">Download employee report</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
                <flux:button size="sm" variant="primary" icon="arrow-down-tray" :href="$reportUrl" data-360-export>Export report</flux:button>
            </div>
        </div>
    </section>

    {{-- ── Live status ── --}}
    @if($live)
        <div @class(['flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl px-4 py-3 ring-1', $toneSoft[$live['tone']] ?? $toneSoft['zinc']]) data-live-status="{{ $live['key'] }}">
            <span class="inline-flex items-center gap-2 text-sm font-bold">
                <span class="relative flex size-2.5">
                    @if($live['session'])<span class="absolute inline-flex size-full animate-ping rounded-full {{ $toneDot[$live['tone']] }} opacity-60"></span>@endif
                    <span class="relative inline-flex size-2.5 rounded-full {{ $toneDot[$live['tone']] ?? 'bg-zinc-400' }}"></span>
                </span>
                {{ $live['label'] }}
            </span>
            @if($live['session'])
                <span class="text-sm" x-data="{ start: {{ (int) $live['session']['start_ms'] }}, now: Date.now(), init() { setInterval(() => this.now = Date.now(), 30000) } }">
                    In since {{ $live['session']['start_label'] }} ·
                    <span class="font-semibold tabular-nums" x-text="(() => { const m = Math.max(0, Math.floor((now - start) / 60000)); return Math.floor(m / 60) + 'h ' + String(m % 60).padStart(2, '0') + 'm' })()">{{ $hm($live['session']['elapsed_minutes']) }}</span>
                </span>
            @endif
            <span class="text-xs opacity-80">
                First in {{ $live['first_in'] ? $dd->time($live['first_in']) : '—' }} · Last out {{ $live['last_out'] ? $dd->time($live['last_out']) : '—' }} · Worked {{ $hm($live['worked_minutes']) }}
            </span>
            <span class="ml-auto text-[11px] font-medium opacity-70">Today · {{ $dd->date(today()) }}</span>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-[minmax(0,1fr)_19rem] xl:grid-cols-[minmax(0,1fr)_21rem]">
        <div class="min-w-0 space-y-5">
            {{-- ── KPIs ── --}}
            @if($kpis !== [])
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3" data-360-kpis>
                    @foreach($kpis as $kpi)
                        <x-pulse.kpi-card :label="$kpi['label']" :value="$kpi['value']" :sub="$kpi['hint']" :icon="$kpiIcon[$kpi['key']] ?? 'chart-bar'" :accent="$kpiAccent[$kpi['tone']] ?? 'brand'" data-kpi="{{ $kpi['key'] }}" />
                    @endforeach
                </div>
            @endif

            {{-- ── Attendance ── --}}
            @if($attendance)
                <x-pulse.card title="Attendance" :subtitle="$rangeLabel" icon="calendar-days" data-360-section="attendance">
                    <x-slot:actions>
                        @if($can['attendance_page'])
                            <a href="{{ route('attendance.employees', ['employee' => $employee->id]) }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">View full attendance →</a>
                        @endif
                    </x-slot:actions>

                    <div class="flex flex-wrap gap-2" data-attendance-counts>
                        @foreach(\App\Services\People\Employee360Service::ATTENDANCE_STATUSES as $key => $label)
                            <button type="button" wire:click="$set('attendanceStatus', '{{ $attendanceStatus === $key ? '' : $key }}')"
                                @class(['inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-semibold ring-1 transition', 'bg-orange-500 text-white ring-orange-500' => $attendanceStatus === $key, 'bg-white text-zinc-700 ring-zinc-200 hover:ring-orange-300 dark:bg-white/5 dark:text-zinc-200 dark:ring-white/10' => $attendanceStatus !== $key])
                                data-count="{{ $key }}">
                                {{ $label }} <span class="tabular-nums">{{ $attendance['counts'][$key] }}</span>
                            </button>
                        @endforeach
                        @if($attendance['missing_checkouts'] > 0)
                            <span class="inline-flex items-center gap-1.5 rounded-full bg-rose-50 px-3 py-1 text-xs font-semibold text-rose-700 ring-1 ring-rose-100 dark:bg-rose-500/10 dark:text-rose-300 dark:ring-rose-500/20">Missing checkout {{ $attendance['missing_checkouts'] }}</span>
                        @endif
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <div class="min-w-0 rounded-xl border border-zinc-100 p-3 dark:border-white/5">
                            <p class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">Attendance trend <span class="font-normal text-zinc-400">· worked hours per day</span></p>
                            <x-dashboard.chart :options="$charts['trend']" wire:key="trend-{{ $employee->id }}-{{ $from }}-{{ $to }}" />
                        </div>
                        <div class="min-w-0 rounded-xl border border-zinc-100 p-3 dark:border-white/5">
                            <p class="text-xs font-semibold text-zinc-700 dark:text-zinc-200">Punctuality <span class="font-normal text-zinc-400">· per week</span></p>
                            <x-dashboard.chart :options="$charts['punctuality']" wire:key="punctuality-{{ $employee->id }}-{{ $from }}-{{ $to }}" />
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-1 gap-4 xl:grid-cols-2">
                        <div class="min-w-0">
                            <p class="mb-2 text-xs font-semibold text-zinc-700 dark:text-zinc-200">
                                Days{{ $attendanceStatus ? ' · '.\App\Services\People\Employee360Service::ATTENDANCE_STATUSES[$attendanceStatus] : '' }}
                                <span class="font-normal text-zinc-400">({{ $attendance['day_log_total'] }})</span>
                            </p>
                            @if($attendance['day_log'] === [])
                                <x-pulse.empty-state icon="calendar" text="No days match this filter in the selected range." />
                            @else
                                <div class="overflow-x-auto rounded-xl border border-zinc-100 dark:border-white/5">
                                    <table class="w-full min-w-[22rem] text-left text-xs" data-day-log>
                                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                                            <tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">In</th><th class="px-3 py-2">Out</th><th class="px-3 py-2">Worked</th><th class="px-3 py-2">Status</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                            @foreach($attendance['day_log'] as $day)
                                                <tr>
                                                    <td class="whitespace-nowrap px-3 py-2 font-medium text-zinc-800 dark:text-zinc-100">{{ $dd->shortDate(\Illuminate\Support\Carbon::parse($day['date'])) }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $fmtMinutes($day['first_in_min']) }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $day['missing_out'] ? 'Missing' : $fmtMinutes($day['last_out_min']) }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2 tabular-nums">{{ $day['worked'] > 0 ? $hm($day['worked']) : '—' }}</td>
                                                    <td class="px-3 py-2"><x-pulse.badge :color="$statusColor[$day['status']] ?? 'zinc'">{{ \App\Services\People\Employee360Service::ATTENDANCE_STATUSES[$day['status']] ?? ucfirst($day['status']) }}</x-pulse.badge></td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                        <div class="min-w-0">
                            <p class="mb-2 text-xs font-semibold text-zinc-700 dark:text-zinc-200">Recent punch log</p>
                            @if($attendance['punches'] === [])
                                <x-pulse.empty-state icon="finger-print" text="No punches in the selected range." />
                            @else
                                <div class="overflow-x-auto rounded-xl border border-zinc-100 dark:border-white/5">
                                    <table class="w-full min-w-[22rem] text-left text-xs" data-punch-log>
                                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                                            <tr><th class="px-3 py-2">Time</th><th class="px-3 py-2">In / Out</th><th class="px-3 py-2">Source</th><th class="px-3 py-2">Status</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                            @foreach($attendance['punches'] as $punch)
                                                <tr>
                                                    <td class="whitespace-nowrap px-3 py-2"><span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $dd->time($punch['at']) }}</span> <span class="text-zinc-400">{{ $dd->shortDate($punch['at']) }}</span></td>
                                                    <td class="px-3 py-2">
                                                        @if($punch['direction'] === 'in')<x-pulse.badge color="emerald">In</x-pulse.badge>
                                                        @elseif($punch['direction'] === 'out')<x-pulse.badge color="blue">Out</x-pulse.badge>
                                                        @else<x-pulse.badge>Scan</x-pulse.badge>@endif
                                                    </td>
                                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-300">{{ $punch['source'] }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2 text-zinc-600 dark:text-zinc-300">{{ $punch['status'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-pulse.card>
            @endif

            {{-- ── Leave ── --}}
            @if($leave)
                <x-pulse.card title="Leave" :subtitle="'Leave year '.$leave['year']->label" icon="sun" data-360-section="leave">
                    <x-slot:actions>
                        @if($can['leave_detail'])
                            <a href="{{ route('time-off.leave-management.employee', $employee) }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">View all →</a>
                        @endif
                    </x-slot:actions>

                    <div class="-mx-1 mb-4 overflow-x-auto px-1">
                        <div class="flex min-w-max gap-1 border-b border-zinc-100 dark:border-white/10" role="tablist">
                            @foreach(\App\Services\People\Employee360Service::LEAVE_TABS as $key => $label)
                                <button type="button" role="tab" aria-selected="{{ $leave['tab'] === $key ? 'true' : 'false' }}" wire:click="setLeaveTab('{{ $key }}')" data-leave-tab="{{ $key }}"
                                    @class(['relative px-3 py-2 text-xs font-semibold', 'text-orange-600 dark:text-orange-400' => $leave['tab'] === $key, 'text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200' => $leave['tab'] !== $key])>
                                    {{ $label }}@if($key === 'pending' && $leave['pending_requests'] > 0) <span class="ml-1 rounded-full bg-amber-100 px-1.5 text-[10px] text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">{{ $leave['pending_requests'] }}</span>@endif
                                    @if($leave['tab'] === $key)<span class="absolute inset-x-0 -bottom-px h-0.5 rounded-full bg-orange-500"></span>@endif
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div class="space-y-4" data-leave-types>
                            <div class="grid grid-cols-3 gap-2 text-center">
                                <div class="rounded-xl bg-violet-50 p-2 dark:bg-violet-500/10"><p class="text-lg font-bold tabular-nums text-violet-700 dark:text-violet-300" data-leave-available>{{ \App\Services\People\Employee360Service::number($leave['available']) }}</p><p class="text-[10px] font-semibold uppercase text-violet-600/80">Available</p></div>
                                <div class="rounded-xl bg-zinc-50 p-2 dark:bg-white/5"><p class="text-lg font-bold tabular-nums text-zinc-800 dark:text-zinc-100" data-leave-taken>{{ \App\Services\People\Employee360Service::number($leave['taken_this_year']) }}</p><p class="text-[10px] font-semibold uppercase text-zinc-500">Taken</p></div>
                                <div class="rounded-xl bg-amber-50 p-2 dark:bg-amber-500/10"><p class="text-lg font-bold tabular-nums text-amber-700 dark:text-amber-300" data-leave-pending>{{ $leave['pending_requests'] }}</p><p class="text-[10px] font-semibold uppercase text-amber-600/80">Pending</p></div>
                            </div>
                            <div class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2 xl:grid-cols-3">
                            @forelse($leave['types'] as $type)
                                @php($total = max($type['used'] + $type['available'], 0.0001))
                                <div class="min-w-0">
                                    <div class="flex items-baseline justify-between gap-2 text-xs">
                                        <span class="truncate font-semibold text-zinc-700 dark:text-zinc-200">{{ $type['name'] }}</span>
                                        <span class="shrink-0 tabular-nums text-zinc-500">{{ \App\Services\People\Employee360Service::number($type['used']) }} used · {{ \App\Services\People\Employee360Service::number($type['available']) }} left</span>
                                    </div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                        <div class="h-full rounded-full bg-violet-500" style="width: {{ min(100, round($type['used'] / $total * 100)) }}%"></div>
                                    </div>
                                </div>
                            @empty
                                <p class="text-xs text-zinc-500">No leave balances this year.</p>
                            @endforelse
                            </div>
                        </div>

                        <div class="min-w-0">
                            @if($leave['requests'] === [])
                                <x-pulse.empty-state icon="sun" text="No leave requests here for the selected range." />
                            @else
                                <div class="overflow-x-auto rounded-xl border border-zinc-100 dark:border-white/5">
                                    <table class="w-full min-w-[30rem] text-left text-xs" data-leave-requests>
                                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                                            <tr><th class="px-3 py-2">Date</th><th class="px-3 py-2">Type</th><th class="px-3 py-2">Days</th><th class="px-3 py-2">Status</th><th class="px-3 py-2">Approver</th><th class="px-3 py-2 text-right">Actions</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                            @foreach($leave['requests'] as $request)
                                                <tr wire:key="leave-{{ $request['id'] }}">
                                                    <td class="whitespace-nowrap px-3 py-2 font-medium text-zinc-800 dark:text-zinc-100">{{ $dd->shortDate($request['start']) }}@if(! $request['start']->isSameDay($request['end'])) – {{ $dd->shortDate($request['end']) }}@endif</td>
                                                    <td class="px-3 py-2">{{ $request['type'] }}</td>
                                                    <td class="px-3 py-2 tabular-nums">{{ \App\Services\People\Employee360Service::number($request['days']) }}</td>
                                                    <td class="px-3 py-2"><x-pulse.badge :color="$leaveColor[$request['status']] ?? 'zinc'">{{ str_replace('_', ' ', $request['status']) }}</x-pulse.badge></td>
                                                    <td class="max-w-[10rem] truncate px-3 py-2 text-zinc-600 dark:text-zinc-300" title="{{ $request['approver'] }}">{{ $request['approver'] ?? '—' }}</td>
                                                    <td class="whitespace-nowrap px-3 py-2 text-right">
                                                        @if($request['inline'])
                                                            <flux:button size="xs" variant="primary" wire:click="approveLeave({{ $request['id'] }})" wire:loading.attr="disabled" data-approve-leave="{{ $request['id'] }}">Approve</flux:button>
                                                            <flux:button size="xs" variant="ghost" wire:click="startReject({{ $request['id'] }})" data-reject-leave="{{ $request['id'] }}">Reject</flux:button>
                                                        @elseif($request['can_decide'])
                                                            <flux:button size="xs" :href="route('time-off.team', ['selectedRequestId' => $request['id']])" wire:navigate>Review</flux:button>
                                                        @elseif($can['leave_detail'])
                                                            <flux:button size="xs" variant="ghost" :href="route('time-off.leave-management.employee', $employee)" wire:navigate>View</flux:button>
                                                        @endif
                                                    </td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </div>
                    </div>
                </x-pulse.card>
            @endif

            {{-- ── Performance, Overtime, Warnings ── --}}
            @if($performance || $overtime || $warnings)
                <div class="grid grid-cols-1 gap-5 md:grid-cols-2 min-[1800px]:grid-cols-3">
                    @if($performance)
                        <x-pulse.card title="Performance" :subtitle="$performance['cycle']?->name ?? 'No active cycle'" icon="chart-bar" data-360-section="performance">
                            <x-slot:actions>
                                @if($performance['url'])
                                    <a href="{{ $performance['url'] }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">View performance →</a>
                                @endif
                            </x-slot:actions>
                            <div class="flex items-center gap-4">
                                @if($performance['score'] !== null)
                                    <x-performance.score-circle :score="$performance['score']" :grade="$performance['grade']" :size="104" label="Score" />
                                @else
                                    {{-- No locked score yet: an empty ring, never a misleading 0. --}}
                                    <div class="flex size-[104px] shrink-0 flex-col items-center justify-center rounded-full border-[10px] border-zinc-100 text-center dark:border-zinc-800" data-performance-no-score>
                                        <span class="text-2xl font-black text-zinc-300 dark:text-zinc-600">—</span>
                                        <span class="text-[9px] font-semibold uppercase text-zinc-400">No score yet</span>
                                    </div>
                                @endif
                                <div class="min-w-0 flex-1 space-y-2 text-xs">
                                    <div class="flex items-center justify-between gap-2"><span class="text-zinc-500">Review status</span>
                                        <x-pulse.badge :color="match ($performance['state']) { 'completed' => 'emerald', 'overdue' => 'rose', 'not_started' => 'zinc', default => 'amber' }" data-performance-state="{{ $performance['state'] }}">{{ $performance['label'] }}</x-pulse.badge></div>
                                    <div class="flex items-center justify-between gap-2"><span class="text-zinc-500">Self review</span><span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $performance['self'] }}</span></div>
                                    <div class="flex items-center justify-between gap-2"><span class="text-zinc-500">Manager review</span><span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $performance['manager'] }}</span></div>
                                    @if($performance['due'])<div class="flex items-center justify-between gap-2"><span class="text-zinc-500">Due</span><span class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $dd->date($performance['due']) }}</span></div>@endif
                                </div>
                            </div>
                            <div class="mt-4 space-y-3 text-xs">
                                @php($goalPct = $performance['goals']['total'] > 0 ? (int) round($performance['goals']['completed'] / $performance['goals']['total'] * 100) : 0)
                                <div>
                                    <div class="flex justify-between"><span class="font-semibold text-zinc-700 dark:text-zinc-200">Goals</span><span class="tabular-nums text-zinc-500">{{ $performance['goals']['completed'] }} / {{ $performance['goals']['total'] }} done</span></div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-sky-500" style="width: {{ $goalPct }}%"></div></div>
                                </div>
                                <div>
                                    <div class="flex justify-between"><span class="font-semibold text-zinc-700 dark:text-zinc-200">KPIs</span><span class="tabular-nums text-zinc-500">{{ $performance['kpis']['progress'] !== null ? $performance['kpis']['progress'].'% · '.$performance['kpis']['total'].' KPIs' : 'None assigned' }}</span></div>
                                    <div class="mt-1 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-orange-500" style="width: {{ min(100, $performance['kpis']['progress'] ?? 0) }}%"></div></div>
                                </div>
                            </div>
                        </x-pulse.card>
                    @endif

                    @if($overtime)
                        <x-pulse.card title="Overtime" :subtitle="$rangeLabel" icon="bolt" data-360-section="overtime">
                            <x-slot:actions>
                                @if(auth()->user()->canApproveOt())
                                    <a href="{{ route('overtime.manage') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">Manage OT →</a>
                                @endif
                            </x-slot:actions>
                            <div class="grid grid-cols-2 gap-3">
                                <div class="rounded-xl bg-amber-50 p-3 dark:bg-amber-500/10"><p class="text-[10px] font-semibold uppercase text-amber-700/80 dark:text-amber-300">Pending requests</p><p class="mt-1 text-xl font-bold tabular-nums text-amber-800 dark:text-amber-200" data-ot-pending>{{ $overtime['pending'] }}</p></div>
                                <div class="rounded-xl bg-emerald-50 p-3 dark:bg-emerald-500/10"><p class="text-[10px] font-semibold uppercase text-emerald-700/80 dark:text-emerald-300">Approved requests</p><p class="mt-1 text-xl font-bold tabular-nums text-emerald-800 dark:text-emerald-200" data-ot-approved>{{ $overtime['approved'] }}</p></div>
                                <div class="rounded-xl bg-orange-50 p-3 dark:bg-orange-500/10"><p class="text-[10px] font-semibold uppercase text-orange-700/80 dark:text-orange-300">Actual OT worked</p><p class="mt-1 text-xl font-bold tabular-nums text-orange-800 dark:text-orange-200" data-ot-worked>{{ \App\Services\People\Employee360Service::number($overtime['worked_hours']) }}h</p></div>
                                <div class="rounded-xl bg-zinc-50 p-3 dark:bg-white/5"><p class="text-[10px] font-semibold uppercase text-zinc-500">Requested (approved)</p><p class="mt-1 text-xl font-bold tabular-nums text-zinc-800 dark:text-zinc-100">{{ \App\Services\People\Employee360Service::number($overtime['requested_hours_approved']) }}h</p></div>
                            </div>
                            @if($overtime['payable'])
                                <p class="mt-3 rounded-xl border border-zinc-100 px-3 py-2 text-xs text-zinc-600 dark:border-white/5 dark:text-zinc-300" data-ot-payable>
                                    Payable, not yet paid: <span class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ \App\Services\People\Employee360Service::number($overtime['payable']['hours']) }}h</span>
                                    · {{ number_format($overtime['payable']['amount'], 2) }}
                                </p>
                            @endif
                            <p class="mt-2 text-[11px] text-zinc-400">Actual OT is measured from real punches on approved requests; the requested window is shown only for reference.</p>
                        </x-pulse.card>
                    @endif

                    @if($warnings)
                        <x-pulse.card title="Warnings & conduct" icon="exclamation-triangle" data-360-section="warnings" :class="$performance && $overtime ? 'md:col-span-2 min-[1800px]:col-span-1' : ''">
                            <x-slot:actions>
                                @if($can['warnings_page'])
                                    <a href="{{ route('performance.warnings.manage') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">View all →</a>
                                @endif
                            </x-slot:actions>
                            <div class="flex items-center gap-3">
                                <div @class(['flex size-12 items-center justify-center rounded-2xl text-xl font-bold tabular-nums', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300' => $warnings['active'] > 0, 'bg-zinc-50 text-zinc-500 dark:bg-white/5' => $warnings['active'] === 0]) data-warnings-active>{{ $warnings['active'] }}</div>
                                <div class="min-w-0 text-xs">
                                    <p class="font-semibold text-zinc-800 dark:text-zinc-100">Active warning{{ $warnings['active'] === 1 ? '' : 's' }}</p>
                                    <p class="text-zinc-500">{{ $warnings['total'] }} on record{{ $warnings['latest'] ? ' · last '.$dd->date($warnings['latest']->issue_date) : '' }}</p>
                                </div>
                            </div>
                            @if($warnings['timeline'] !== [])
                                <ol class="mt-4 space-y-3 border-l border-zinc-100 pl-4 dark:border-white/10">
                                    @foreach($warnings['timeline'] as $letter)
                                        <li class="relative text-xs">
                                            <span class="absolute -left-[1.3rem] top-1 size-2 rounded-full bg-rose-400"></span>
                                            <p class="font-semibold text-zinc-800 dark:text-zinc-100">{{ \Illuminate\Support\Str::headline((string) $letter->warning_type) }} <span class="font-normal text-zinc-400">· {{ $dd->date($letter->issue_date) }}</span></p>
                                            <p class="text-zinc-500">{{ \Illuminate\Support\Str::limit((string) $letter->reason, 90) }}</p>
                                        </li>
                                    @endforeach
                                </ol>
                            @else
                                <x-pulse.empty-state class="mt-4" icon="check-badge" text="No warnings on record." />
                            @endif
                        </x-pulse.card>
                    @endif
                </div>
            @endif

            {{-- ── Activity (full width, so the table never scrolls sideways on desktop) ── --}}
            @if($activity)
                        <x-pulse.card title="Activity & logs" icon="clipboard-document-list" :flush="true" data-360-section="activity">
                            <x-slot:actions>
                                @if($activity['full'])
                                    <a href="{{ route('settings.audit-log') }}" wire:navigate class="text-xs font-semibold text-orange-600 hover:text-orange-700 dark:text-orange-400">View all →</a>
                                @endif
                            </x-slot:actions>
                            @if($activity['entries'] === [])
                                <div class="px-5 pb-5"><x-pulse.empty-state icon="clipboard-document-list" text="No recorded activity you can see yet." /></div>
                            @else
                                <div class="overflow-x-auto">
                                    <table class="w-full min-w-[30rem] text-left text-xs" data-activity-log>
                                        <thead class="bg-zinc-50 text-[10px] uppercase tracking-wide text-zinc-500 dark:bg-white/5">
                                            <tr><th class="px-4 py-2">Date &amp; time</th><th class="px-4 py-2">Action</th><th class="px-4 py-2">Details</th><th class="px-4 py-2">By</th></tr>
                                        </thead>
                                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                            @foreach($activity['entries'] as $entry)
                                                <tr>
                                                    <td class="whitespace-nowrap px-4 py-2 text-zinc-500">{{ $dd->dateTime($entry['at']) }}</td>
                                                    <td class="px-4 py-2 font-semibold text-zinc-800 dark:text-zinc-100">{{ $entry['action'] }}</td>
                                                    <td class="max-w-[26rem] truncate px-4 py-2 text-zinc-600 dark:text-zinc-300" title="{{ $entry['details'] }}">{{ $entry['details'] }}</td>
                                                    <td class="whitespace-nowrap px-4 py-2 text-zinc-600 dark:text-zinc-300">{{ $entry['by'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            @endif
                        </x-pulse.card>
            @endif
        </div>

        {{-- ── Employee details (right on desktop, below on tablet/mobile) ── --}}
        <aside class="min-w-0 space-y-5 lg:sticky lg:top-4 lg:self-start" data-360-details>
            <x-pulse.card title="Employee details" icon="identification">
                <dl class="space-y-2.5 text-xs">
                    @foreach(array_filter([
                        'Email' => $details['email'],
                        'Phone' => $details['phone'],
                        'Office' => $details['office'],
                        'Work mode' => $details['work_mode'],
                        'Shift' => $details['shift'],
                        'Department' => $details['department'],
                        'Designation' => $details['designation'],
                        'Joining date' => $details['joining_date'] ? $dd->date($details['joining_date']) : null,
                        'Employment type' => $details['employment_type'],
                    ], fn ($v) => filled($v)) as $term => $value)
                        <div class="flex items-start justify-between gap-3">
                            <dt class="shrink-0 text-zinc-500">{{ $term }}</dt>
                            <dd class="min-w-0 break-words text-right font-medium text-zinc-800 dark:text-zinc-100">{{ $value }}</dd>
                        </div>
                    @endforeach
                </dl>
            </x-pulse.card>

            @if($can['contact'])
                <x-pulse.card title="Emergency contact" icon="phone">
                    <p class="whitespace-pre-line text-xs text-zinc-700 dark:text-zinc-200">{{ $details['emergency_contact'] ?: 'Not recorded.' }}</p>
                </x-pulse.card>
            @endif

            <x-pulse.card title="Reporting hierarchy" icon="users">
                <div class="flex flex-col items-center gap-1 text-center text-xs">
                    @if($details['manager'])
                        <div class="w-full rounded-xl border border-zinc-100 px-3 py-2 dark:border-white/5">
                            <p class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $details['manager']['name'] }}</p>
                            <p class="text-zinc-500">{{ $details['manager']['title'] ?? 'Reporting manager' }}</p>
                        </div>
                        <flux:icon.arrow-down class="size-4 text-zinc-300" />
                    @endif
                    <div class="w-full rounded-xl bg-orange-50 px-3 py-2 ring-1 ring-orange-100 dark:bg-orange-500/10 dark:ring-orange-500/20">
                        <p class="font-semibold text-orange-800 dark:text-orange-200">{{ $identity['name'] }}</p>
                        <p class="text-orange-700/80 dark:text-orange-300/80">{{ $identity['designation'] ?? 'Employee' }}</p>
                    </div>
                    @if($details['reports'] > 0)
                        <flux:icon.arrow-down class="size-4 text-zinc-300" />
                        <p class="text-zinc-500">{{ $details['reports'] }} direct report{{ $details['reports'] === 1 ? '' : 's' }}</p>
                    @endif
                </div>
            </x-pulse.card>

            <x-pulse.card title="Quick actions" icon="bolt">
                <div class="grid grid-cols-1 gap-2">
                    @if($can['attendance_page'])
                        <flux:button size="sm" icon="calendar-days" :href="route('attendance.employees', ['employee' => $employee->id])" class="justify-start">View full attendance</flux:button>
                    @endif
                    @if($can['leave_detail'])
                        <flux:button size="sm" icon="sun" :href="route('time-off.leave-management.employee', $employee)" wire:navigate class="justify-start">Leave history</flux:button>
                    @endif
                    @if($performance && $performance['url'])
                        <flux:button size="sm" icon="chart-bar" :href="$performance['url']" wire:navigate class="justify-start">Performance review</flux:button>
                    @endif
                    <flux:button size="sm" icon="arrow-down-tray" :href="$reportUrl" class="justify-start">Download employee report</flux:button>
                </div>
            </x-pulse.card>
        </aside>
    </div>

    @if($rejectingId)
        <x-pulse.modal title="Reject leave request" subtitle="The employee sees this reason." close="$wire.cancelReject()" width="small">
            <flux:textarea wire:model="rejectReason" rows="3" label="Reason" placeholder="Why is this request rejected?" />
            <x-slot:footer>
                <flux:button variant="ghost" wire:click="cancelReject">Cancel</flux:button>
                <flux:button variant="danger" wire:click="confirmReject" data-confirm-reject>Reject request</flux:button>
            </x-slot:footer>
        </x-pulse.modal>
    @endif
</div>
</flux:main>
