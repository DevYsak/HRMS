@use('App\Enums\AttendanceMode')
@use('App\Enums\PunchMethod')

<flux:main class="min-h-screen bg-[#FFF8F3] dark:bg-white/5 p-4 md:p-6">

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">Employees Attendance</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">All Employees</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        {{-- Week navigator --}}
        <div class="flex items-center gap-1 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 p-1 shadow-sm">
            <button wire:click="previousWeek" type="button" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-left class="size-4" /></button>
            <button wire:click="thisWeek" type="button" class="min-w-[9rem] px-2 py-1 text-center text-xs font-bold text-zinc-700 dark:text-zinc-200 transition hover:text-orange-500" title="Jump to this week">{{ $weekLabel }}</button>
            <button wire:click="nextWeek" type="button" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-right class="size-4" /></button>
        </div>
        <div class="flex items-center gap-1.5 rounded-xl border {{ $date ? 'border-orange-400 ring-1 ring-orange-200' : 'border-orange-100' }} bg-white dark:bg-zinc-900 px-2 py-1 shadow-sm" title="Pick a single date (overrides the week)">
            <span class="text-[10px] font-bold uppercase tracking-wider {{ $date ? 'text-orange-500' : 'text-zinc-400' }}">Day</span>
            <input type="date" wire:model.live="date" class="w-[8rem] border-0 bg-transparent p-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300 focus:ring-0">
        </div>
        <a href="{{ route('attendance.command-center') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.bolt class="size-4 text-orange-500" /> Command Center</a>
        <a href="{{ route('attendance.reports') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.document-chart-bar class="size-4 text-orange-500" /> Reports</a>
        <a href="{{ route('attendance.executive') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.presentation-chart-line class="size-4 text-orange-500" /> Executive</a>
        <a href="{{ route('attendance.biometric-control') }}" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.finger-print class="size-4 text-orange-500" /> Biometric</a>
        <button wire:click="exportCsv" type="button" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export CSV</button>
        <button wire:click="openMarkModal" type="button" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-orange-300/40 transition hover:shadow-xl"><flux:icon.pencil-square class="size-4" /> Mark Attendance</button>
    </div>
</div>

<div class="space-y-4">

{{-- ═══════════════ TODAY KPI STRIP ═══════════════ --}}
@php
    $kpis = [
        ['label' => 'Total Employees', 'value' => $stats['total'], 'sub' => 'active workforce', 'icon' => 'users', 'color' => '#71717a'],
        ['label' => 'Present Today', 'value' => $stats['present'], 'sub' => $stats['present_pct'].'% of total', 'icon' => 'check-circle', 'color' => '#10b981'],
        ['label' => 'Absent', 'value' => $stats['absent'], 'sub' => $stats['absent_pct'].'% of total', 'icon' => 'x-circle', 'color' => '#ef4444'],
        ['label' => 'On Time', 'value' => $stats['on_time'], 'sub' => 'within grace', 'icon' => 'clock', 'color' => '#3b82f6'],
        ['label' => 'Late Arrivals', 'value' => $stats['late'], 'sub' => $stats['late_pct'].'% of present', 'icon' => 'exclamation-triangle', 'color' => '#f59e0b'],
        ['label' => 'WFH / Hybrid', 'value' => $stats['wfh'], 'sub' => 'working remotely', 'icon' => 'home', 'color' => '#8b5cf6'],
    ];
@endphp
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 xl:grid-cols-6">
    @foreach($kpis as $k)
        <div class="flex items-center gap-3 rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm transition hover:-translate-y-0.5 hover:shadow-md">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl" style="background: {{ $k['color'] }}1a; color: {{ $k['color'] }};"><flux:icon :icon="$k['icon']" class="size-5" /></span>
            <div class="min-w-0">
                <div class="text-xl font-black leading-none tabular-nums text-zinc-900 dark:text-white">{{ $k['value'] }}</div>
                <div class="mt-0.5 truncate text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $k['label'] }}</div>
                <div class="truncate text-[9px] text-zinc-400">{{ $k['sub'] }}</div>
            </div>
        </div>
    @endforeach
</div>

{{-- ═══════════════ PENDING REGULARISATIONS ═══════════════ --}}
@if($pendingRegularisations->isNotEmpty())
    <div class="rounded-[18px] border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4 shadow-sm">
        <div class="mb-3 flex items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-lg shadow-amber-200"><flux:icon.exclamation-triangle class="size-5" /></span>
            <div>
                <div class="text-sm font-black text-amber-900">{{ $pendingRegularisations->count() }} pending regularisation {{ \Illuminate\Support\Str::plural('request', $pendingRegularisations->count()) }}</div>
                <div class="text-xs text-amber-700">Approving auto-updates the employee's hours, overtime, score and payroll attendance.</div>
            </div>
        </div>
        <div class="space-y-2">
            @foreach($pendingRegularisations as $req)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200/70 bg-white/80 dark:bg-zinc-900/80 px-3 py-2">
                    <div class="flex min-w-0 items-center gap-3 text-xs">
                        <span class="font-black text-zinc-900 dark:text-white">{{ $req->employee?->user?->name ?? '—' }}</span>
                        <span class="text-zinc-500 dark:text-zinc-400">{{ \Carbon\Carbon::parse($req->work_date)->format('d M Y') }}</span>
                        <span class="font-mono font-bold text-amber-700">{{ \Carbon\Carbon::parse($req->requested_check_in)->format('H:i') }} → {{ \Carbon\Carbon::parse($req->requested_check_out)->format('H:i') }}</span>
                        <span class="hidden truncate italic text-zinc-400 md:inline">“{{ \Illuminate\Support\Str::limit($req->reason, 60) }}”</span>
                    </div>
                    <button wire:click="openReviewModal({{ $req->id }})" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-amber-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-amber-600"><flux:icon.eye class="size-3" /> Review</button>
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ═══════════════ PRESENCE TREND ═══════════════ --}}
@php
    $trendChart = [
        'chart' => ['type' => 'bar', 'height' => 200, 'stacked' => false, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 600]],
        'colors' => ['#F97316', '#FBBF24'],
        'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'legend' => ['position' => 'top', 'horizontalAlign' => 'right', 'fontSize' => '11px', 'labels' => ['colors' => '#9CA3AF']],
        'xaxis' => ['categories' => collect($trend)->pluck('label')->all(), 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]],
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]],
        'tooltip' => ['theme' => 'light'],
        'series' => [
            ['name' => 'Present', 'data' => collect($trend)->pluck('present')->all()],
            ['name' => 'Late', 'data' => collect($trend)->pluck('late')->all()],
        ],
    ];
@endphp
@if(count($trend) > 0 && collect($trend)->sum('present') > 0)
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Presence Overview</div>
            <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500">{{ $weekLabel }}</span>
        </div>
        <x-dashboard.chart :options="$trendChart" id="presence-trend" wire:key="trend-{{ $dateFrom }}-{{ $dateTo }}" class="-mb-2" />
    </div>
@endif

{{-- ═══════════════ ATTENDANCE LOG ═══════════════ --}}
<div class="overflow-hidden rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-orange-100/70 px-5 py-3.5">
        <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white"><flux:icon.clock class="size-4 text-orange-500" /> Attendance Log
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· {{ $attendances->total() }} records</span>
        </h3>
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative">
                <flux:icon.magnifying-glass class="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-zinc-400" />
                <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search employee…"
                    class="w-48 rounded-lg border border-orange-100 bg-white dark:bg-zinc-900 py-1.5 pl-8 pr-2.5 text-xs font-semibold text-zinc-600 dark:text-zinc-300 placeholder:text-zinc-400 focus:border-orange-400 focus:ring-0">
            </div>
            <x-clean-select model="status" :live="true"
                :options="[
                    ['value' => '', 'label' => 'All status'],
                    ['value' => 'on_time', 'label' => 'On Time'],
                    ['value' => 'late', 'label' => 'Late'],
                    ['value' => 'remote', 'label' => 'Remote'],
                    ['value' => 'absent', 'label' => 'Absent'],
                ]" />
            {{-- Date range — filter the punch logs for an arbitrary span --}}
            <div class="flex items-center gap-1.5 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 px-2 py-1" title="Filter logs by date range">
                <flux:icon.calendar-days class="size-3.5 text-zinc-400" />
                <input type="date" wire:model.live="dateFrom" max="{{ now()->toDateString() }}" aria-label="From date"
                    class="w-[7.5rem] border-0 bg-transparent p-0.5 text-xs font-semibold tabular-nums text-zinc-600 dark:text-zinc-300 focus:ring-0">
                <span class="text-zinc-300">–</span>
                <input type="date" wire:model.live="dateTo" max="{{ now()->toDateString() }}" aria-label="To date"
                    class="w-[7.5rem] border-0 bg-transparent p-0.5 text-xs font-semibold tabular-nums text-zinc-600 dark:text-zinc-300 focus:ring-0">
            </div>
            @if($search || $date || $status)
                <button wire:click="$set('search','');$set('date','');$set('status','')" class="inline-flex items-center gap-1 rounded-lg bg-zinc-100 dark:bg-zinc-800 px-2.5 py-1.5 text-xs font-bold text-zinc-500 dark:text-zinc-400 transition hover:bg-zinc-200"><flux:icon.x-mark class="size-3.5" /> Reset</button>
            @endif
        </div>
    </div>
    <div class="overflow-x-auto">
        @if($attendances->count() > 0)
            <table class="w-full text-left">
                <thead class="border-b border-orange-100/70 bg-orange-50/40">
                    <tr class="text-[9px] font-bold uppercase tracking-widest text-zinc-400">
                        <th class="px-5 py-2.5">Employee</th>
                        <th class="py-2.5">Date</th>
                        <th class="py-2.5">Check In</th>
                        <th class="py-2.5">Check Out</th>
                        <th class="py-2.5">Break</th>
                        <th class="py-2.5">Hours</th>
                        <th class="py-2.5">Method</th>
                        <th class="py-2.5">Status</th>
                        <th class="px-5 py-2.5 text-right">Mode</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50">
                    @foreach($attendances as $log)
                        @php
                            $name = $log->employee?->user?->name ?? '—';
                            $initials = collect(explode(' ', $name))->map(fn ($n) => $n[0] ?? '')->take(2)->join('');
                            [$badge, $statusLabel] = match ($log->status) {
                                'on_time' => ['bg-emerald-50 text-emerald-600', 'Present'],
                                'late' => ['bg-amber-50 text-amber-600', 'Late'],
                                'absent' => ['bg-rose-50 text-rose-500', 'Absent'],
                                'remote' => ['bg-blue-50 text-blue-600', 'Remote'],
                                default => ['bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400', ucfirst($log->status ?? '—')],
                            };
                            $rowMode = AttendanceMode::tryFromValue($log->work_mode ?? 'office');
                            $inMethod = PunchMethod::tryFrom((string) $log->check_in_method);
                            $outMethod = PunchMethod::tryFrom((string) $log->check_out_method);
                            $methods = collect([$inMethod, $outMethod])->filter()->unique();
                            $hrs = (float) $log->total_hours;
                        @endphp
                        <tr wire:click="openEmployeeDrawer({{ $log->employee_id }})" class="cursor-pointer text-xs transition hover:bg-orange-50/40">
                            <td class="px-5 py-2.5">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex size-8 shrink-0 items-center justify-center rounded-xl bg-orange-100 text-[10px] font-black text-orange-600">{{ $initials }}</div>
                                    <div class="min-w-0">
                                        <div class="truncate font-black text-zinc-900 dark:text-white">{{ $name }}</div>
                                        <div class="text-[9px] text-zinc-400">{{ $log->employee?->employee_id ?? '—' }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5"><div class="font-bold text-zinc-800 dark:text-zinc-100">{{ $log->date->format('d M') }}</div><div class="text-[9px] text-zinc-400">{{ $log->date->format('D') }}</div></td>
                            <td class="py-2.5">
                                <span class="font-mono font-bold tabular-nums text-zinc-800 dark:text-zinc-100">{{ $log->check_in?->format('h:i A') ?? '—' }}</span>
                                @if($log->is_late)<div class="text-[9px] font-bold text-amber-600">+{{ (int) ($log->late_minutes ?? 0) }}m late</div>@endif
                            </td>
                            <td class="py-2.5 font-mono tabular-nums">
                                @if($log->check_out)<span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $log->check_out->format('h:i A') }}</span>
                                @elseif($log->check_in && $log->date->isToday())<span class="inline-flex items-center gap-1 font-bold text-emerald-600"><span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span> LIVE</span>
                                @elseif($log->check_in)<span class="font-bold text-amber-500">missing</span>
                                @else<span class="text-zinc-300">—</span>@endif
                            </td>
                            <td class="py-2.5 text-zinc-500 dark:text-zinc-400">{{ (int) ($log->break_minutes ?? 0) }}m</td>
                            <td class="py-2.5"><span class="font-black tabular-nums {{ $hrs >= 8 ? 'text-emerald-600' : ($hrs > 0 ? 'text-zinc-800 dark:text-zinc-100' : 'text-zinc-300') }}">{{ $hrs > 0 ? number_format($hrs, 1).'h' : '—' }}</span></td>
                            <td class="py-2.5">
                                @forelse($methods as $m)
                                    <span class="mr-1 inline-flex items-center gap-0.5 rounded px-1.5 py-0.5 text-[9px] font-bold {{ $m->chipClass() }}"><flux:icon :icon="$m->icon()" class="size-2.5" /> {{ $m->label() }}</span>
                                @empty<span class="text-zinc-300">—</span>@endforelse
                            </td>
                            <td class="py-2.5"><span class="rounded-full px-2 py-0.5 text-[9px] font-bold {{ $badge }}">{{ $statusLabel }}</span></td>
                            <td class="px-5 py-2.5 text-right"><span class="inline-flex items-center rounded px-1.5 py-0.5 text-[8px] font-bold uppercase {{ $rowMode->chipClass() }}">{{ $rowMode->shortLabel() }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @else
            <div class="py-14 text-center text-sm text-zinc-400">
                <flux:icon.calendar-days class="mx-auto mb-2 size-9 opacity-30" />
                No attendance records for this range.
                <div class="mt-1 text-xs text-zinc-300">Try another week, clear the day filter, or reset the filters.</div>
            </div>
        @endif
    </div>
    @if($attendances->hasPages())
        <div class="flex flex-wrap items-center justify-between gap-2 border-t border-orange-100/70 bg-orange-50/30 px-5 py-3">
            <div class="text-xs text-zinc-400">Showing {{ $attendances->firstItem() }}–{{ $attendances->lastItem() }} of {{ $attendances->total() }}</div>
            {{ $attendances->links() }}
        </div>
    @endif
</div>

</div>{{-- /space-y --}}

{{-- ══════════════════════════════════════════
EMPLOYEE 360 DRAWER (480px, right)
══════════════════════════════════════════ --}}
@if($drawerEmployeeId && ! empty($drawer))
    <div class="fixed inset-0 z-40" x-data="{ show: false }" x-init="requestAnimationFrame(() => show = true)"
         x-on:keydown.escape.window="$wire.closeDrawer()">
        <div class="absolute inset-0 bg-black/30 backdrop-blur-sm transition-opacity duration-300"
             :class="show ? 'opacity-100' : 'opacity-0'" @click="$wire.closeDrawer()"></div>

        <aside class="absolute right-0 top-0 flex h-full w-[480px] max-w-full flex-col bg-[#FFF8F3] dark:bg-white/5 shadow-2xl transition-transform duration-300 ease-out"
               :class="show ? 'translate-x-0' : 'translate-x-full'">

            {{-- Header --}}
            <div class="flex items-start justify-between gap-3 border-b border-orange-100 bg-white dark:bg-zinc-900 px-5 py-4">
                <div class="flex items-center gap-3">
                    @if($drawer['photo'])
                        <img src="{{ \Storage::url($drawer['photo']) }}" alt="{{ $drawer['name'] }}" class="size-12 rounded-2xl object-cover ring-2 ring-orange-100">
                    @else
                        <div class="flex size-12 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-sm font-black text-white">{{ collect(explode(' ', $drawer['name']))->map(fn($n) => $n[0] ?? '')->take(2)->join('') }}</div>
                    @endif
                    <div class="min-w-0">
                        <div class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $drawer['name'] }}</div>
                        <div class="text-[10px] text-zinc-400">{{ $drawer['code'] }} · {{ $drawer['designation'] }}</div>
                        <div class="mt-0.5 flex flex-wrap gap-x-3 text-[10px] text-zinc-500 dark:text-zinc-400">
                            <span class="inline-flex items-center gap-1"><flux:icon.building-office-2 class="size-3 text-orange-400" /> {{ $drawer['department'] }}</span>
                            <span class="inline-flex items-center gap-1"><flux:icon.user class="size-3 text-orange-400" /> {{ $drawer['manager'] }}</span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    @php
                        [$dBadge, $dDot] = match($drawer['status']) {
                            'Working' => ['bg-emerald-100 text-emerald-700', 'bg-emerald-500 animate-pulse'],
                            'On Break' => ['bg-orange-100 text-orange-700', 'bg-orange-500 animate-pulse'],
                            'Completed' => ['bg-rose-100 text-rose-600', 'bg-rose-500'],
                            default => ['bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400', 'bg-zinc-400'],
                        };
                    @endphp
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $dBadge }}"><span class="size-1.5 rounded-full {{ $dDot }}"></span>{{ $drawer['status'] }}</span>
                    <button type="button" @click="$wire.closeDrawer()" class="rounded-lg p-1 text-zinc-400 transition hover:bg-orange-50 hover:text-zinc-600 dark:text-zinc-300"><flux:icon.x-mark class="size-5" /></button>
                </div>
            </div>

            {{-- Body --}}
            <div class="flex-1 space-y-3 overflow-y-auto p-4">

                {{-- KPI row --}}
                <div class="grid grid-cols-4 gap-2">
                    @foreach([
                        ['Attendance', $drawer['attendance_pct'].'%', '#10b981'],
                        ['Score', $drawer['score'].'/100', '#F97316'],
                        ['Late (mo.)', $drawer['late_count'], $drawer['late_count'] ? '#f59e0b' : '#10b981'],
                        ['Leave Bal.', rtrim(rtrim(number_format($drawer['leave_balance'], 1), '0'), '.'), '#14b8a6'],
                    ] as [$k, $v, $c])
                        <div class="rounded-xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-2.5 text-center shadow-sm">
                            <div class="text-sm font-black tabular-nums" style="color: {{ $c }}">{{ $v }}</div>
                            <div class="text-[8px] font-bold uppercase tracking-wider text-zinc-400">{{ $k }}</div>
                        </div>
                    @endforeach
                </div>

                {{-- Today --}}
                <div class="rounded-2xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Today</div>
                    @if($drawer['today'])
                        <div class="grid grid-cols-3 gap-2 text-center">
                            @foreach([
                                ['In', $drawer['today']['in'] ?? '—'], ['Out', $drawer['today']['out'] ?? 'live'],
                                ['Worked', $drawer['today']['worked']], ['Break', $drawer['today']['break'].'m'],
                                ['Overtime', $drawer['today']['overtime']], ['Mode', strtoupper($drawer['today']['mode'] ?? '—')],
                            ] as [$k, $v])
                                <div class="rounded-lg bg-orange-50/50 px-2 py-1.5"><div class="text-xs font-black tabular-nums text-zinc-900 dark:text-white">{{ $v }}</div><div class="text-[8px] font-bold uppercase text-zinc-400">{{ $k }}</div></div>
                            @endforeach
                        </div>
                        <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-[10px] text-zinc-500 dark:text-zinc-400">
                            @if($drawer['today']['device'])<span class="inline-flex items-center gap-1"><flux:icon.cpu-chip class="size-3 text-orange-400" /> {{ $drawer['today']['device'] }}</span>@endif
                            @if($drawer['today']['location'])<span class="inline-flex items-center gap-1"><flux:icon.map-pin class="size-3 text-orange-400" /> {{ $drawer['today']['location'] }}</span>@endif
                            @if($drawer['today']['is_late'])<span class="font-bold text-amber-600">Late today</span>@endif
                        </div>
                    @else
                        <p class="text-xs text-zinc-400">No punch recorded today.</p>
                    @endif
                </div>

                {{-- Today's timeline --}}
                @if(! empty($drawer['punches']))
                    <div class="rounded-2xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Today's Punches · {{ $drawer['punch_count'] ?? count($drawer['punches']) }}</div>
                        @if(! empty($drawer['punches_partial']))
                            <div class="mb-2 flex items-center gap-1.5 rounded-lg bg-amber-50 px-2.5 py-1 text-[10px] font-semibold text-amber-700 dark:bg-amber-500/10 dark:text-amber-400">
                                <flux:icon.information-circle class="size-3.5" /> Showing {{ count($drawer['punches']) }} synced punches — full stream in the v2 dashboard.
                            </div>
                        @endif
                        <div class="max-h-36 space-y-1 overflow-y-auto">
                            @foreach($drawer['punches'] as $p)
                                <div class="flex items-center gap-2 rounded-lg bg-zinc-50/70 px-2.5 py-1 text-xs">
                                    <flux:icon :icon="$p['icon']" class="size-3.5 text-orange-400" />
                                    <span class="font-black tabular-nums text-zinc-800 dark:text-zinc-100">{{ $p['time'] }}</span>
                                    <span class="text-[10px] text-zinc-400">{{ $p['method'] ?? 'Punch' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Pending regularizations --}}
                @if(! empty($drawer['pending']))
                    <div class="rounded-2xl border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4 shadow-sm">
                        <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-amber-700">Pending Regularization · {{ count($drawer['pending']) }}</div>
                        <div class="space-y-2">
                            @foreach($drawer['pending'] as $pr)
                                <div class="rounded-xl border border-amber-200/70 bg-white/80 dark:bg-zinc-900/80 p-2.5">
                                    <div class="flex items-center justify-between text-xs">
                                        <span class="font-black text-zinc-900 dark:text-white">{{ $pr['date'] }}</span>
                                        <span class="flex items-center gap-1.5">
                                            @if(! empty($pr['stage']))<span class="rounded-full bg-amber-100 px-2 py-0.5 text-[9px] font-bold uppercase tracking-wide text-amber-700">{{ $pr['stage'] }}</span>@endif
                                            <span class="font-mono font-bold text-amber-700">{{ $pr['window'] }}</span>
                                        </span>
                                    </div>
                                    <p class="mt-0.5 truncate text-[10px] italic text-zinc-400">“{{ $pr['reason'] }}”</p>
                                    <div class="mt-1.5 flex gap-1.5">
                                        <button wire:click="quickApproveRegularisation({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-emerald-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-emerald-600"><flux:icon.check class="size-3" /> Approve</button>
                                        <button wire:click="openReviewModal({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg bg-rose-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-rose-600"><flux:icon.x-mark class="size-3" /> Reject</button>
                                        <button wire:click="openReviewModal({{ $pr['id'] }})" class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-2.5 py-1 text-[10px] font-bold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-50 dark:bg-zinc-800/50"><flux:icon.pencil-square class="size-3" /> Edit</button>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Late + break history --}}
                <div class="grid grid-cols-2 gap-3">
                    <div class="rounded-2xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-3 shadow-sm">
                        <div class="mb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Late History</div>
                        @forelse($drawer['late_history'] as $lh)
                            <div class="flex items-center justify-between py-0.5 text-[11px]"><span class="text-zinc-600 dark:text-zinc-300">{{ $lh['date'] }}</span><span class="font-bold text-amber-600">+{{ $lh['mins'] }}m</span></div>
                        @empty<p class="text-[10px] text-emerald-600">No late arrivals 🎉</p>@endforelse
                    </div>
                    <div class="rounded-2xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-3 shadow-sm">
                        <div class="mb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Break History</div>
                        @forelse($drawer['break_history'] as $bh)
                            <div class="flex items-center justify-between py-0.5 text-[11px]"><span class="text-zinc-600 dark:text-zinc-300">{{ $bh['date'] }}</span><span class="font-bold {{ $bh['mins'] > 60 ? 'text-amber-600' : 'text-zinc-800 dark:text-zinc-100' }}">{{ $bh['mins'] }}m</span></div>
                        @empty<p class="text-[10px] text-zinc-400">No breaks recorded.</p>@endforelse
                    </div>
                </div>

                {{-- Attendance history --}}
                <div class="rounded-2xl border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Attendance History · last 7</div>
                    <div class="space-y-1">
                        @forelse($drawer['history'] as $h)
                            @php $hc = match($h['status']) { 'on_time' => 'text-emerald-600', 'late' => 'text-amber-600', default => 'text-zinc-400' }; @endphp
                            <div class="flex items-center justify-between rounded-lg px-2 py-1 text-[11px] odd:bg-orange-50/40">
                                <span class="w-12 font-bold text-zinc-700 dark:text-zinc-200">{{ $h['date'] }}</span>
                                <span class="font-mono tabular-nums text-zinc-600 dark:text-zinc-300">{{ $h['in'] ?? '—' }} → {{ $h['out'] ?? '—' }}</span>
                                <span class="font-black tabular-nums text-zinc-800 dark:text-zinc-100">{{ $h['hours'] ? number_format((float) $h['hours'], 1).'h' : '—' }}</span>
                                <span class="text-[9px] font-bold uppercase {{ $hc }}">{{ str_replace('_', ' ', $h['status']) }}</span>
                            </div>
                        @empty<p class="text-[10px] text-zinc-400">No records this month.</p>@endforelse
                    </div>
                </div>
            </div>
        </aside>
    </div>
@endif

{{-- ══════════════════════════════════════════
REVIEW REGULARISATION MODAL
══════════════════════════════════════════ --}}
@if($showReviewModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
         x-data x-on:keydown.escape.window="$wire.set('showReviewModal', false)">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showReviewModal', false)"></div>
        <div class="relative max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-xl ring ring-black/5">
            <button type="button" @click="$wire.set('showReviewModal', false)" class="absolute right-4 top-4 text-zinc-400 transition-colors hover:text-zinc-600 dark:text-zinc-300">
                <flux:icon.x-mark class="size-5" />
            </button>

            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">Review Regularisation</flux:heading>
                    <flux:subheading>Approving auto-updates hours, overtime, score &amp; payroll.</flux:subheading>
                </div>
                @if($activeRequest)
                    @if($regularisationLocked)
                        <div class="flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3">
                            <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                            <div>
                                <p class="text-sm font-semibold text-amber-800">Already approved by Super Admin ({{ $lockedByName }})</p>
                                <p class="mt-0.5 text-xs text-amber-700">A mandatory comment is required to override this approval.</p>
                            </div>
                        </div>
                    @endif
                    <div class="space-y-3 rounded-xl border border-orange-100 bg-orange-50/40 p-4 text-sm">
                        <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Employee</span><span class="font-bold text-zinc-900 dark:text-white">{{ $activeRequest->employee?->user?->name ?? '—' }}</span></div>
                        <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Date</span><span class="font-bold text-zinc-900 dark:text-white">{{ \Carbon\Carbon::parse($activeRequest->work_date ?? $activeRequest->date)->format('d M Y') }}</span></div>
                        <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Requested Time</span>
                            <span class="font-mono font-bold text-orange-600">{{ \Carbon\Carbon::parse($activeRequest->requested_check_in)->format('H:i') }} → {{ \Carbon\Carbon::parse($activeRequest->requested_check_out)->format('H:i') }}</span>
                        </div>
                        @if($activeRequest->attendance)
                            <div class="flex items-center justify-between"><span class="text-zinc-500 dark:text-zinc-400">Recorded Time</span>
                                <span class="font-mono text-zinc-600 dark:text-zinc-300">{{ $activeRequest->attendance->check_in?->format('H:i') ?? '—' }} → {{ $activeRequest->attendance->check_out?->format('H:i') ?? '—' }}</span>
                            </div>
                        @endif
                        <div class="border-t border-orange-100 pt-2">
                            <p class="mb-1 text-[10px] uppercase tracking-wider text-zinc-400">Reason</p>
                            <p class="text-xs italic text-zinc-700 dark:text-zinc-200">"{{ $activeRequest->reason }}"</p>
                        </div>
                        @if($activeRequest->attachment_path)
                            <div class="flex items-center justify-between border-t border-orange-100 pt-2">
                                <span class="text-[10px] uppercase tracking-wider text-zinc-400">Attachment</span>
                                <a href="{{ \Illuminate\Support\Facades\Storage::url($activeRequest->attachment_path) }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-bold text-orange-600 hover:underline"><flux:icon.paper-clip class="size-3.5" /> View document</a>
                            </div>
                        @endif
                        {{-- Approval chain progress --}}
                        <div class="border-t border-orange-100 pt-2">
                            <p class="mb-1.5 text-[10px] uppercase tracking-wider text-zinc-400">Approval Flow · currently at <b class="text-orange-600">{{ $activeRequest->stageLabel() }}</b></p>
                            @php $stageNum = \App\Models\AttendanceRegularisation::STAGES[$activeRequest->stage ?? 'manager_review'] ?? 1; @endphp
                            <div class="flex items-center gap-1.5">
                                @foreach(['Manager', 'HR', 'Admin'] as $i => $st)
                                    <span class="flex-1 rounded-full px-2 py-1 text-center text-[10px] font-bold {{ $activeRequest->status === 'approved' || $stageNum > $i + 1 ? 'bg-emerald-100 text-emerald-700' : ($stageNum === $i + 1 && $activeRequest->status === 'pending' ? 'bg-orange-100 text-orange-700 ring-1 ring-orange-300' : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800') }}">{{ $st }}</span>
                                    @if($i < 2)<flux:icon.chevron-right class="size-3 shrink-0 text-zinc-300" />@endif
                                @endforeach
                            </div>
                            @if(! empty($activeRequest->approval_trail))
                                <div class="mt-2 space-y-1">
                                    @foreach($activeRequest->approval_trail as $step)
                                        <div class="flex items-center gap-1.5 text-[10px] text-zinc-500 dark:text-zinc-400">
                                            <flux:icon :icon="($step['action'] ?? '') === 'approved' ? 'check-circle' : 'x-circle'" class="size-3 {{ ($step['action'] ?? '') === 'approved' ? 'text-emerald-500' : 'text-rose-500' }}" />
                                            <b>{{ $step['name'] ?? 'Reviewer' }}</b> {{ $step['action'] ?? '' }} at {{ str_replace('_', ' ', $step['stage'] ?? '') }} · {{ \Carbon\Carbon::parse($step['at'])->format('d M h:i A') }}
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    </div>
                    <flux:textarea wire:model="reviewComment"
                        label="{{ $regularisationLocked ? 'Comment (Required for Override)' : 'HR Comment (Required for Rejection)' }}"
                        rows="2" placeholder="Add a comment..." />
                    @error('reviewComment')
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @enderror
                    <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                        <button type="button" @click="$wire.set('showReviewModal', false)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50">Cancel</button>
                        <flux:button wire:click="rejectRegularisation" variant="ghost" class="!text-red-600 hover:!bg-red-50">Reject</flux:button>
                        <flux:button wire:click="approveRegularisation" variant="primary">Approve</flux:button>
                    </div>
                @endif
            </div>
        </div>
    </div>
@endif

{{-- ══════════════════════════════════════════
HR MARK ATTENDANCE MODAL
══════════════════════════════════════════ --}}
@if($showMarkModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
         x-data x-on:keydown.escape.window="$wire.set('showMarkModal', false)">
        <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showMarkModal', false)"></div>
        <div class="relative max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-2xl bg-white dark:bg-zinc-900 p-6 shadow-xl ring ring-black/5">
            <button type="button" @click="$wire.set('showMarkModal', false)" class="absolute right-4 top-4 text-zinc-400 transition-colors hover:text-zinc-600 dark:text-zinc-300">
                <flux:icon.x-mark class="size-5" />
            </button>

            <div class="space-y-5">
                <div class="flex items-start gap-3">
                    <div class="shrink-0 rounded-xl bg-orange-50 p-2.5"><flux:icon.pencil-square class="size-5 text-orange-500" /></div>
                    <div>
                        <flux:heading size="lg">Mark Attendance for Employee</flux:heading>
                        <flux:subheading>Creates a regularisation request pending approval.</flux:subheading>
                    </div>
                </div>
                <div class="flex gap-3 rounded-xl border border-amber-100 bg-amber-50 p-3">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0 text-amber-600" />
                    <p class="text-xs leading-relaxed text-amber-700">This creates a <strong>Regularisation Request</strong> sent to the employee's <strong>Line Manager</strong> for approval.</p>
                </div>
                <div class="space-y-4">
                    <x-clean-select model="markEmployeeId" label="Employee" placeholder="Select employee..." :live="false" :required="true"
                        :options="collect($allEmployees)->map(fn ($emp) => ['value' => $emp->id, 'label' => $emp->user->name.' ('.($emp->employee_id ?? 'No ID').')'])->all()" />
                    <flux:input wire:model="markDate" type="date" label="Date of Attendance" max="{{ now()->format('Y-m-d') }}" required />
                    <div class="grid grid-cols-2 gap-4">
                        <flux:input wire:model="markCheckIn" type="time" label="Check-in Time" required />
                        <flux:input wire:model="markCheckOut" type="time" label="Check-out Time" required />
                    </div>
                    <div>
                        <flux:label>Work Mode</flux:label>
                        <div class="mt-1.5 grid grid-cols-3 gap-2">
                            @foreach(['office' => 'Office', 'wfh' => 'WFH', 'remote' => 'Remote'] as $val => $label)
                                <button type="button" wire:click="$set('markWorkMode', '{{ $val }}')"
                                    class="rounded-xl border py-2 text-sm font-bold transition-all {{ $markWorkMode === $val ? 'border-orange-400 bg-orange-50 text-orange-700' : 'border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400 hover:border-zinc-300' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    </div>
                    <flux:textarea wire:model="markReason" label="Reason for HR Entry" placeholder="e.g. Employee's biometric failed, system issue..." rows="2" required />
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" @click="$wire.set('showMarkModal', false)" class="rounded-xl border border-zinc-200 dark:border-zinc-800 px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 transition-colors hover:bg-zinc-50 dark:bg-zinc-800/50">Cancel</button>
                    <flux:button wire:click="submitMarkAttendance" variant="primary" icon="paper-airplane">Submit for Approval</flux:button>
                </div>
            </div>
        </div>
    </div>
@endif

</flux:main>
