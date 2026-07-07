@use('App\Enums\AttendanceMode')
@use('App\Enums\PunchMethod')
@use('App\Support\UserAgent')
@use('Illuminate\Support\Facades\Storage')

<flux:main class="min-h-screen bg-[#FFF8F3] dark:bg-white/5 p-4 md:p-6" x-data="{
    currentTime: '',
    updateClock() { this.currentTime = new Date().toLocaleTimeString('en-IN', { hour12: true, hour: '2-digit', minute: '2-digit', second: '2-digit' }); }
}" x-init="updateClock(); setInterval(() => updateClock(), 1000)">

@php
    $emp = auth()->user()->employee;

    $presentCount = (int) ($stats['present'] ?? 0);
    $lateCount    = (int) ($stats['late'] ?? 0);
    $leaveCount   = (int) ($stats['leaves'] ?? 0);
    $onTimeCount  = max(0, $presentCount - $lateCount);

    $pStart = match($statsPeriod) {
        'this_week'  => now()->startOfWeek(\Carbon\Carbon::SUNDAY),
        'last_month' => now()->subMonth()->startOfMonth(),
        '3_months'   => now()->subMonths(2)->startOfMonth(),
        'year'       => now()->startOfYear(),
        default      => now()->startOfMonth(),
    };
    $pEnd = ($statsPeriod === 'last_month') ? now()->subMonth()->endOfMonth() : now();
    if ($pEnd->gt(now())) { $pEnd = now(); }
    $totalWorkingDays = max(1, (int) $pStart->diffInDaysFiltered(fn($d) => ! $d->isSunday(), $pEnd));

    $attPct = round(min(100, ($presentCount / $totalWorkingDays) * 100), 1);
    $score  = (int) ($analytics['attendance_score'] ?? 0);
    $compliance = (int) ($analytics['shift_compliance'] ?? 0);
    $avgBreak = (int) ($analytics['avg_break'] ?? 0);

    $stdHours = (float) ($shift->standard_hours ?? 9);
    $otHours  = round(collect($chartDaily)->sum(fn($d) => max(0, (float) $d['hours'] - $stdHours)), 1);
    $otDays   = collect($chartDaily)->filter(fn($d) => (float) $d['hours'] > $stdHours)->count();
    $otMinTotal = (int) round($otHours * 60);
    $avgWorkMin = $presentCount > 0 ? (int) round(collect($chartDaily)->sum('hours') * 60 / max(1, $presentCount)) : 0;

    // Today live state
    $heroMode = AttendanceMode::tryFromValue($todayAttendance->work_mode ?? $workMode);
    $isIn   = $todayAttendance && ! $todayAttendance->check_out;
    $isDone = $todayAttendance && $todayAttendance->check_out;
    $workedMin = 0;
    if ($todayAttendance && $todayAttendance->check_in) {
        $endT = $todayAttendance->check_out ?? now();
        $workedMin = max(0, (int) $todayAttendance->check_in->diffInMinutes($endT) - (int) ($todayAttendance->break_minutes ?? 0));
    }
    $targetMin = (int) round($stdHours * 60);
    $progress  = $targetMin > 0 ? min(100, (int) round($workedMin / $targetMin * 100)) : 0;
    $workedLabel = intdiv($workedMin, 60).'h '.str_pad((string) ($workedMin % 60), 2, '0', STR_PAD_LEFT).'m';
    $targetLabel = intdiv($targetMin, 60).'h '.($targetMin % 60).'m';
    $remainingMin = max(0, $targetMin - $workedMin);
    $liveStart = $isIn ? $todayAttendance->check_in->timestamp : null;

    // Punch summary
    $sum = $todaySummary;
    $inM  = PunchMethod::tryFrom((string) ($todayAttendance->check_in_method ?? $sum?->first_punch_method));
    $outM = PunchMethod::tryFrom((string) ($todayAttendance->check_out_method ?? $sum?->last_punch_method));
    $punchMethods = collect([$inM, $outM])->filter()->unique();
    $punchSource = $punchMethods->isNotEmpty() ? $punchMethods->map->label()->implode(' + ') : '—';
    $breakMin = (int) ($todayAttendance->break_minutes ?? $sum?->break_minutes ?? 0);
    $totalPunches = (int) ($sum?->raw_punch_count ?? count($attendanceJourney));
    $deviceName = $biometricDevice?->name ?? $sum?->device_serial ?? '—';
    $lastSync = $biometricDevice?->last_synced_at ?? $sum?->synced_at;
    $connected = (bool) ($lastSync && \Carbon\Carbon::parse($lastSync)->gt(now()->subMinutes(30)));

    $missingCount = count($attendanceAlerts);
    $expectedLogout = ($shift && $shift->end_time) ? \Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '—';
@endphp

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">My Attendance</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">My Attendance</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <span class="inline-flex items-center gap-2 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-semibold text-zinc-600 dark:text-zinc-300 shadow-sm"><flux:icon.calendar-days class="size-4 text-orange-500" /> {{ now()->format('d F Y') }}</span>
        <div class="flex items-center gap-1 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 p-1 shadow-sm">
            @foreach(['today' => 'Today', 'this_week' => 'Week', 'this_month' => 'Month', '3_months' => '3 Months', 'year' => 'Year'] as $val => $label)
                <button wire:click="$set('statsPeriod', '{{ $val }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-bold transition {{ $statsPeriod === $val ? 'bg-orange-500 text-white shadow' : 'text-zinc-500 dark:text-zinc-400 hover:bg-orange-50' }}">{{ $label }}</button>
            @endforeach
        </div>
        <x-clean-select model="analyticsMode" :live="true"
            :options="[['value' => '', 'label' => 'All modes'], ...collect(AttendanceMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all()]" />
        <div class="flex items-center gap-1.5 rounded-xl border {{ $statsPeriod === 'custom' ? 'border-orange-400 ring-1 ring-orange-200' : 'border-orange-100' }} bg-white dark:bg-zinc-900 px-2 py-1 shadow-sm">
            <span class="text-[10px] font-bold uppercase tracking-wider {{ $statsPeriod === 'custom' ? 'text-orange-500' : 'text-zinc-400' }}">Custom</span>
            <input type="date" wire:model.live="rangeFrom" class="w-[7.5rem] border-0 bg-transparent p-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300 focus:ring-0">
            <span class="text-zinc-300">–</span>
            <input type="date" wire:model.live="rangeTo" class="w-[7.5rem] border-0 bg-transparent p-1 text-xs font-semibold text-zinc-600 dark:text-zinc-300 focus:ring-0">
        </div>
        <button wire:click="exportLog" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export</button>
    </div>
</div>

{{-- ═══════════════ HERO (glassmorphism) ═══════════════ --}}
<div class="relative mb-4 overflow-hidden rounded-[22px] border border-white/70 bg-gradient-to-br from-white/90 via-orange-50/70 to-orange-100/40 p-6 shadow-xl shadow-orange-200/30 backdrop-blur-xl"
     x-data="{ start: {{ $liveStart ?? 'null' }}, live: '{{ $workedLabel }}',
        tick(){ if(this.start===null) return; const s=Math.floor(Date.now()/1000)-this.start; const h=Math.floor(s/3600),m=Math.floor((s%3600)/60),sec=s%60; this.live=String(h).padStart(2,'0')+'h '+String(m).padStart(2,'0')+'m '+String(sec).padStart(2,'0')+'s'; } }"
     x-init="tick(); if(start!==null) setInterval(()=>tick(),1000)">
    <div class="pointer-events-none absolute -right-16 -top-24 size-64 rounded-full bg-orange-300/30 blur-3xl"></div>
    <div class="relative grid grid-cols-1 gap-6 lg:grid-cols-12 lg:items-center">
        {{-- Identity --}}
        <div class="lg:col-span-5">
            <div class="flex items-center gap-4">
                <div class="relative shrink-0">
                    @if($emp?->photo)
                        <img src="{{ Storage::url($emp->photo) }}" alt="{{ auth()->user()->name }}" class="size-20 rounded-2xl object-cover ring-4 ring-white shadow-lg">
                    @else
                        <div class="flex size-20 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-amber-400 text-2xl font-black text-white shadow-lg">{{ auth()->user()->initials() }}</div>
                    @endif
                    <span class="absolute -bottom-1 -right-1 size-5 rounded-full border-4 border-white {{ $isIn ? 'animate-pulse bg-emerald-500' : ($isDone ? 'bg-zinc-400' : 'bg-amber-500') }}"></span>
                </div>
                <div class="min-w-0">
                    <div class="flex items-center gap-1.5">
                        <h2 class="truncate text-xl font-black text-zinc-900 dark:text-white">{{ auth()->user()->name }}</h2>
                        <flux:icon.check-badge class="size-5 text-orange-500" />
                    </div>
                    <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $emp?->employee_code ? 'EMP'.str_pad((string) $emp->employee_code, 5, '0', STR_PAD_LEFT) : '' }} · {{ $emp?->jobTitle?->name ?? $emp?->jobTitle?->title ?? 'Employee' }}</div>
                    <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                        <span class="inline-flex items-center gap-1"><flux:icon.building-office-2 class="size-3.5 text-orange-400" /> {{ $emp?->department?->name ?? '—' }}</span>
                        @if($emp?->manager)<span class="inline-flex items-center gap-1"><flux:icon.user class="size-3.5 text-orange-400" /> {{ $emp->manager->name }} (Manager)</span>@endif
                    </div>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-3 gap-2">
                <div class="rounded-xl bg-white/70 dark:bg-zinc-900/70 p-2.5 shadow-sm">
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Shift Time</div>
                    <div class="text-[11px] font-black text-zinc-800 dark:text-zinc-100">{{ $shift ? \Carbon\Carbon::parse($shift->start_time)->format('g:i A').' - '.\Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '—' }}</div>
                </div>
                <div class="rounded-xl bg-white/70 dark:bg-zinc-900/70 p-2.5 shadow-sm">
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Attendance Mode</div>
                    <div class="text-[11px] font-black text-zinc-800 dark:text-zinc-100">{{ $heroMode->label() }}</div>
                </div>
                <div class="rounded-xl bg-white/70 dark:bg-zinc-900/70 p-2.5 shadow-sm">
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Grace Time</div>
                    <div class="text-[11px] font-black text-zinc-800 dark:text-zinc-100">{{ $shift->grace_minutes ?? 5 }} mins</div>
                </div>
            </div>
        </div>

        {{-- Live timer --}}
        <div class="lg:col-span-4">
            <div class="rounded-2xl bg-white/60 dark:bg-zinc-900/60 p-4 shadow-sm">
                <div class="mb-1 flex items-center gap-2">
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2.5 py-0.5 text-[10px] font-black uppercase tracking-wider text-emerald-700">
                        <span class="size-1.5 rounded-full bg-emerald-500 {{ $isIn ? 'animate-pulse' : '' }}"></span>{{ $isIn ? 'Working' : ($isDone ? 'Completed' : 'Not In') }}
                    </span>
                    @if($todayAttendance)<span class="text-[11px] text-zinc-400">since {{ $todayAttendance->check_in->format('h:i A') }}</span>@endif
                </div>
                <div class="text-3xl font-black tabular-nums text-zinc-900 dark:text-white" x-text="live">{{ $workedLabel }}</div>
                <div class="text-[11px] text-zinc-400">Current Working Time</div>
                <div class="mt-3 h-2 w-full overflow-hidden rounded-full bg-orange-100">
                    <div class="h-full rounded-full bg-gradient-to-r from-orange-500 to-amber-400 transition-all duration-700" style="width: {{ $progress }}%"></div>
                </div>
                <div class="mt-1.5 flex justify-between text-[10px] text-zinc-400">
                    <span>Remaining: <strong class="text-zinc-600 dark:text-zinc-300">{{ intdiv($remainingMin, 60) }}h {{ $remainingMin % 60 }}m</strong></span>
                    <span>Expected Logout: <strong class="text-zinc-600 dark:text-zinc-300">{{ $expectedLogout }}</strong></span>
                </div>
            </div>
        </div>

        {{-- Ring + score --}}
        <div class="lg:col-span-3">
            <div class="flex items-center gap-4">
                <div class="relative grid size-24 shrink-0 place-items-center">
                    <svg class="size-24 -rotate-90" viewBox="0 0 36 36">
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#FFEDD5" stroke-width="3.5" />
                        <circle cx="18" cy="18" r="15.9" fill="none" stroke="#F97316" stroke-width="3.5" stroke-linecap="round" stroke-dasharray="{{ $progress }}, 100" />
                    </svg>
                    <div class="absolute text-center">
                        <div class="text-xl font-black text-zinc-900 dark:text-white">{{ $progress }}%</div>
                        <div class="text-[8px] font-bold uppercase text-zinc-400">Progress</div>
                    </div>
                </div>
                <div>
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">Attendance Score</div>
                    <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $score }}<span class="text-sm text-zinc-400">/100</span></div>
                    <span class="mt-1 inline-flex rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700">{{ $score >= 80 ? 'Excellent' : ($score >= 60 ? 'Good' : 'Needs Focus') }}</span>
                </div>
            </div>
        </div>
    </div>

    {{-- Action buttons --}}
    <div class="relative mt-5 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
        @if(! $todayAttendance)
            <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'in' })" class="inline-flex items-center justify-center gap-2 rounded-xl border border-emerald-200 bg-white dark:bg-zinc-900 py-3 text-sm font-bold text-emerald-600 shadow-sm transition hover:bg-emerald-50 hover:shadow"><flux:icon.arrow-right-end-on-rectangle class="size-5" /> Clock In</button>
        @else
            <span class="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 py-3 text-sm font-bold text-zinc-400"><flux:icon.check-circle class="size-5" /> Clocked In</span>
        @endif
        @if($isIn)
            <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'out' })" class="inline-flex items-center justify-center gap-2 rounded-xl border border-rose-200 bg-white dark:bg-zinc-900 py-3 text-sm font-bold text-rose-500 shadow-sm transition hover:bg-rose-50 hover:shadow"><flux:icon.arrow-left-start-on-rectangle class="size-5" /> Clock Out</button>
        @else
            <span class="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 py-3 text-sm font-bold text-zinc-400"><flux:icon.arrow-left-start-on-rectangle class="size-5" /> Clock Out</span>
        @endif
        @if($isIn && ! $activeBreak)
            <button wire:click="startBreak" class="inline-flex items-center justify-center gap-2 rounded-xl border border-orange-200 bg-orange-50 py-3 text-sm font-bold text-orange-600 shadow-sm transition hover:bg-orange-100"><flux:icon.pause class="size-5" /> Start Break</button>
        @elseif($activeBreak)
            <button wire:click="endBreak" class="inline-flex items-center justify-center gap-2 rounded-xl border border-amber-300 bg-amber-500 py-3 text-sm font-bold text-white shadow transition hover:bg-amber-600"><flux:icon.play class="size-5" /> End Break</button>
        @else
            <span class="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 py-3 text-sm font-bold text-zinc-400"><flux:icon.pause class="size-5" /> Start Break</span>
        @endif
        <span class="inline-flex items-center justify-center gap-2 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 py-3 text-sm font-bold text-zinc-400"><flux:icon.play class="size-5" /> End Break</span>
        <button type="button" @click="$flux.modal('regularisation-modal').show()" class="inline-flex items-center justify-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 py-3 text-sm font-bold text-white shadow-lg shadow-orange-300/40 transition hover:shadow-xl"><flux:icon.pencil-square class="size-5" /> Request Regularization</button>
    </div>
</div>

<div class="space-y-4">

{{-- ═══════════════ ATTENDANCE HEALTH + QUICK ACTIONS ═══════════════ --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
    {{-- Health strip --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-9">
        <div class="mb-3 text-sm font-black text-zinc-900 dark:text-white">Attendance Health</div>
        @php
            $health = [
                ['label' => 'Attendance %', 'value' => $attPct.'%', 'icon' => 'chart-pie', 'color' => '#F97316', 'trend' => '↑ '.$attPct.'%'],
                ['label' => 'Attendance Score', 'value' => $score.'/100', 'icon' => 'shield-check', 'color' => '#10b981', 'trend' => '↑ '.$compliance.'%'],
                ['label' => 'Present Days', 'value' => $presentCount, 'icon' => 'check-badge', 'color' => '#3b82f6', 'trend' => 'of '.$totalWorkingDays.' working'],
                ['label' => 'Late Arrivals', 'value' => $lateCount, 'icon' => 'exclamation-triangle', 'color' => '#f59e0b', 'trend' => 'this period'],
                ['label' => 'Missing Punch', 'value' => $missingCount, 'icon' => 'flag', 'color' => $missingCount ? '#ef4444' : '#10b981', 'trend' => $missingCount ? 'action needed' : 'all clear'],
                ['label' => 'Avg Working / day', 'value' => intdiv($avgWorkMin,60).'h '.($avgWorkMin%60).'m', 'icon' => 'clock', 'color' => '#6366f1', 'trend' => 'this period'],
                ['label' => 'Overtime', 'value' => $otHours.'h', 'icon' => 'bolt', 'color' => '#8b5cf6', 'trend' => $otDays.' day(s)'],
                ['label' => 'Avg Break / day', 'value' => $avgBreak.'m', 'icon' => 'pause', 'color' => '#0ea5e9', 'trend' => 'per day'],
                ['label' => 'Leave Balance', 'value' => rtrim(rtrim(number_format($leaveBalance, 1), '0'), '.'), 'icon' => 'calendar-days', 'color' => '#14b8a6', 'trend' => 'days'],
            ];
        @endphp
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-5">
            @foreach($health as $h)
                <div class="flex items-center gap-3 rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-3 transition hover:-translate-y-0.5 hover:shadow-md">
                    <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-xl" style="background: {{ $h['color'] }}1a; color: {{ $h['color'] }};"><flux:icon :icon="$h['icon']" class="size-4" /></span>
                    <div class="min-w-0">
                        <div class="truncate text-base font-black leading-none tabular-nums text-zinc-900 dark:text-white">{{ $h['value'] }}</div>
                        <div class="mt-0.5 truncate text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $h['label'] }}</div>
                        <div class="truncate text-[9px] text-zinc-400">{{ $h['trend'] }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Quick actions --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-3">
        <div class="mb-3 text-sm font-black text-zinc-900 dark:text-white">Quick Actions</div>
        <div class="grid grid-cols-2 gap-2">
            @php
                $canApprove = auth()->user()->canApproveLeave();
                // Gated pages only for managers/HR; employees get working equivalents.
                $qa = [
                    ['Attendance History', 'clock', $canApprove && \Route::has('attendance.employees') ? route('attendance.employees') : '#attendance-log', null],
                    ['Apply Leave', 'calendar-days', \Route::has('time-off.my') ? route('time-off.my') : '#', null],
                    ['Regularization', 'pencil-square', '#', 'regularise'],
                    ['Download Report', 'arrow-down-tray', '#', 'export'],
                    ['My Overtime', 'bolt', \Route::has('overtime.my') ? route('overtime.my') : '#', null],
                    $canApprove && \Route::has('attendance.team')
                        ? ['My Team', 'users', route('attendance.team'), null]
                        : ['WFH Requests', 'home', \Route::has('wfh.my') ? route('wfh.my') : '#attendance-log', null],
                ];
            @endphp
            @foreach($qa as [$label, $icon, $href, $action])
                <a href="{{ $href }}"
                    @if($action === 'regularise') @click.prevent="$flux.modal('regularisation-modal').show()" @endif
                    @if($action === 'export') wire:click.prevent="exportLog" @endif
                    class="flex flex-col items-center gap-1.5 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-3 text-center transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow">
                    <span class="inline-flex size-8 items-center justify-center rounded-lg bg-orange-50 text-orange-500"><flux:icon :icon="$icon" class="size-4" /></span>
                    <span class="text-[10px] font-bold text-zinc-600 dark:text-zinc-300">{{ $label }}</span>
                </a>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════════ SMART ALERTS ═══════════════ --}}
@if(empty($attendanceAlerts))
    <div class="flex items-center gap-3 rounded-[18px] border border-emerald-200 bg-gradient-to-r from-emerald-50 to-white p-4 shadow-sm">
        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-emerald-500 text-white shadow-lg shadow-emerald-200"><flux:icon.check-badge class="size-5" /></span>
        <div><div class="text-sm font-black text-emerald-900">No attendance issues today</div><div class="text-xs text-emerald-700">All your punches are complete and reconciled.</div></div>
    </div>
@else
    <div class="rounded-[18px] border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 p-4 shadow-sm">
        <div class="flex items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-amber-500 text-white shadow-lg shadow-amber-200"><flux:icon.exclamation-triangle class="size-5" /></span>
            <div><div class="text-sm font-black text-amber-900">{{ count($attendanceAlerts) }} attendance {{ \Illuminate\Support\Str::plural('alert', count($attendanceAlerts)) }}</div><div class="text-xs text-amber-700">Regularise to correct working hours, overtime &amp; attendance.</div></div>
        </div>
        <div class="mt-3 space-y-2">
            @foreach($attendanceAlerts as $alert)
                <div class="flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-200/70 bg-white/80 dark:bg-zinc-900/80 px-3 py-2">
                    <div class="flex items-center gap-2 text-xs"><flux:icon.exclamation-circle class="size-4 text-amber-500" /><span class="font-bold text-amber-900">{{ $alert['label'] }}</span><span class="text-amber-700">· {{ $alert['detail'] }}</span></div>
                    @if($alert['action'] ?? true)
                        <button wire:click="openRegularisation('{{ $alert['date'] }}')" class="inline-flex shrink-0 items-center gap-1 rounded-lg bg-amber-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-amber-600"><flux:icon.pencil-square class="size-3" /> Regularize</button>
                    @else
                        <span class="text-[10px] font-bold uppercase tracking-wider text-amber-400">Info</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
@endif

{{-- ═══════════════ JOURNEY + SHIFT PROGRESS + BIOMETRIC ═══════════════ --}}
@php $journey = count($attendanceJourney) ? $attendanceJourney : $todayTimeline; $isJourney = count($attendanceJourney) > 0; @endphp
<div class="grid grid-cols-1 items-start gap-4 lg:grid-cols-12">
    {{-- Attendance Journey --}}
    @php
        $jFirst = $todayAttendance?->check_in ?? $sum?->first_punch;
        $jLast = $todayAttendance?->check_out ?? $sum?->last_punch;
    @endphp
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-5">
        <div class="mb-4 flex items-center justify-between">
            <div class="flex items-center gap-2">
                <div class="text-sm font-black text-zinc-900 dark:text-white">Today's Attendance Journey</div>
                @if(count($journey))<span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500">{{ count($journey) }} events</span>@endif
            </div>
            <button type="button" @click="$flux.modal('regularisation-modal').show()" class="text-[11px] font-bold text-orange-500 hover:underline">Request Regularization</button>
        </div>
        @if(count($journey) > 0)
            <div class="relative max-h-[430px] overflow-y-auto pr-1" wire:loading.class="opacity-40" wire:target="statsPeriod,rangeFrom,rangeTo">
                @foreach($journey as $ev)
                    @php
                        [$dot, $ic, $tint, $cardTint] = match($ev['type']) {
                            'in'     => ['bg-emerald-500', 'arrow-right-end-on-rectangle', 'text-emerald-600', 'border-emerald-100 bg-emerald-50/40'],
                            'late'   => ['bg-rose-500', 'exclamation-triangle', 'text-rose-600', 'border-rose-100 bg-rose-50/40'],
                            'break'  => ['bg-orange-500', 'pause', 'text-orange-600', 'border-orange-100 bg-orange-50/40'],
                            'resume' => ['bg-blue-500', 'play', 'text-blue-600', 'border-blue-100 bg-blue-50/40'],
                            'out'    => ['bg-rose-600', 'arrow-left-start-on-rectangle', 'text-rose-700', 'border-rose-100 bg-rose-50/30'],
                            default  => ['bg-zinc-400', 'clock', 'text-zinc-500 dark:text-zinc-400', 'border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/40'],
                        };
                        $evMethod = ($ev['method'] ?? null) instanceof PunchMethod ? $ev['method'] : PunchMethod::tryFrom((string) ($ev['method'] ?? ''));
                        $gap = $ev['gap_min'] ?? null;
                        $gapLabel = $gap !== null ? ($gap >= 60 ? intdiv($gap, 60).'h '.($gap % 60).'m' : $gap.' mins') : null;
                        $hasDetail = ! empty($ev['lat']) || ! empty($ev['verify']) || ! empty($ev['source']);
                    @endphp

                    {{-- Duration connector from previous event --}}
                    @if(! $loop->first && $gapLabel)
                        <div class="relative ml-[15px] flex items-center gap-2 py-1 pl-6">
                            <span class="absolute left-0 top-0 h-full w-px bg-orange-100"></span>
                            <flux:icon.arrow-down class="size-3 text-orange-300" />
                            <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[9px] font-bold text-orange-500">{{ $gapLabel }}</span>
                            <span class="text-[9px] text-zinc-400">
                                {{ in_array($ev['type'], ['resume']) ? 'break duration' : (in_array($ev['type'], ['break', 'out']) ? 'since last entry' : 'since previous') }}
                            </span>
                        </div>
                    @endif

                    <div x-data="{ x: false }" class="relative flex items-start gap-3 {{ $loop->first ? '' : 'mt-1' }}">
                        @unless($loop->last)<span class="absolute left-[15px] top-9 h-[calc(100%-4px)] w-px bg-orange-100"></span>@endunless
                        <span class="mt-0.5 inline-flex size-8 shrink-0 items-center justify-center rounded-full {{ $dot }} text-white shadow"><flux:icon :icon="$ic" class="size-4" /></span>
                        <div @click="x = !x" role="button" tabindex="0" @keydown.enter="x = !x"
                            class="flex-1 cursor-pointer rounded-xl border {{ $cardTint }} px-3 py-2 text-left transition duration-200 hover:-translate-y-0.5 hover:shadow-md">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $ev['time'] }}</span>
                                <span class="flex items-center gap-1.5">
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold text-emerald-700">Success</span>
                                    @if($hasDetail)<flux:icon.chevron-down class="size-3 text-zinc-400 transition-transform" ::class="x ? 'rotate-180' : ''" />@endif
                                </span>
                            </div>
                            <div class="text-xs font-bold {{ $tint }}">{{ $evMethod?->label() ?? $ev['title'] }}</div>
                            <div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-[10px] text-zinc-400">
                                <span>{{ $ev['title'] }}</span>
                                @if(! empty($ev['location']))<span class="inline-flex items-center gap-0.5"><flux:icon.map-pin class="size-3" /> {{ $ev['location'] }}</span>@endif
                                @if(! empty($ev['device']) && is_string($ev['device']))<span class="inline-flex items-center gap-0.5"><flux:icon.cpu-chip class="size-3" /> {{ $ev['device'] }}</span>@endif
                            </div>
                            @if($hasDetail)
                                <div x-show="x" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100"
                                    class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-zinc-100 dark:border-zinc-800 pt-1.5 text-[10px] text-zinc-500 dark:text-zinc-400">
                                    @if(! empty($ev['source']))<span class="uppercase tracking-wide">{{ $ev['source'] === 'web' ? 'Web Punch' : ucfirst($ev['source']) }}</span>@endif
                                    @if(! empty($ev['verify']))<span>Verify code {{ $ev['verify'] }}</span>@endif
                                    @if(! empty($ev['lat']) && ! empty($ev['lng']))
                                        <a href="https://www.google.com/maps?q={{ $ev['lat'] }},{{ $ev['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-orange-500 hover:underline" @click.stop><flux:icon.map-pin class="size-3" /> {{ $ev['lat'] }}, {{ $ev['lng'] }}</a>
                                    @endif
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex h-32 flex-col items-center justify-center text-center text-zinc-300"><flux:icon.clock class="mb-2 size-8" /><p class="text-xs">No punches recorded today.</p></div>
        @endif
        {{-- Today's Summary footer --}}
        <div class="mt-4 grid grid-cols-4 gap-x-2 gap-y-3 border-t border-zinc-100 dark:border-zinc-800 pt-3 text-center">
            @foreach([
                ['Working', $workedLabel],
                ['Break', $breakMin >= 60 ? intdiv($breakMin, 60).'h '.($breakMin % 60).'m' : $breakMin.'m'],
                ['Overtime', intdiv($otMinTotal, 60).'h '.($otMinTotal % 60).'m'],
                ['Punches', $totalPunches ?: (count($attendanceJourney) ?: '—')],
                ['First Punch', $jFirst ? \Carbon\Carbon::parse($jFirst)->format('h:i A') : '—'],
                ['Last Punch', $jLast ? \Carbon\Carbon::parse($jLast)->format('h:i A') : '—'],
                ['Score', $score.'/100'],
            ] as [$k, $v])
                <div><div class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $v }}</div><div class="text-[9px] font-bold uppercase text-zinc-400">{{ $k }}</div></div>
            @endforeach
            <div class="grid place-items-center"><span class="inline-flex items-center gap-1 rounded-full {{ $isDone ? 'bg-rose-100 text-rose-600' : ($isIn ? 'bg-emerald-100 text-emerald-700' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-500 dark:text-zinc-400') }} px-2 py-0.5 text-[10px] font-bold">{{ $isDone ? 'Completed' : ($isIn ? 'Working' : '—') }}</span></div>
        </div>
    </div>

    {{-- Shift Progress --}}
    @php
        [$stBg, $stDot, $stLabel] = match(true) {
            (bool) $activeBreak => ['bg-orange-100 text-orange-700', 'bg-orange-500 animate-pulse', 'On Break'],
            $isIn && ($todayAttendance?->is_late) => ['bg-amber-100 text-amber-700', 'bg-amber-500 animate-pulse', 'Working · Late'],
            $isIn => ['bg-emerald-100 text-emerald-700', 'bg-emerald-500 animate-pulse', 'Working'],
            $isDone => ['bg-rose-100 text-rose-600', 'bg-rose-500', 'Shift Completed'],
            $heroMode->value === 'wfh' => ['bg-violet-100 text-violet-700', 'bg-violet-500', 'WFH · Not In'],
            default => ['bg-zinc-800 text-white', 'bg-zinc-400', 'Absent / Not In'],
        };
    @endphp
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-3"
        x-data="{ p: 0 }" x-init="setTimeout(() => p = {{ $progress }}, 150)">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Shift Progress</div>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $stBg }}"><span class="size-1.5 rounded-full {{ $stDot }}"></span>{{ $stLabel }}</span>
        </div>

        {{-- Live clock --}}
        <div class="mb-3 flex items-center justify-center gap-2 rounded-xl bg-orange-50/60 py-1.5 text-xs">
            <flux:icon.clock class="size-3.5 text-orange-400" />
            <span class="font-black tabular-nums text-zinc-800 dark:text-zinc-100" x-text="currentTime"></span>
            <span class="text-[10px] text-zinc-400">IST</span>
        </div>

        {{-- Animated ring --}}
        <div class="mb-1 flex justify-center">
            <div class="relative grid size-32 place-items-center">
                <svg class="size-32 -rotate-90" viewBox="0 0 36 36">
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="#FFEDD5" stroke-width="3" />
                    <circle cx="18" cy="18" r="15.9" fill="none" stroke="#F97316" stroke-width="3" stroke-linecap="round"
                        class="transition-all duration-1000 ease-out" :stroke-dasharray="p + ', 100'" stroke-dasharray="0, 100" />
                </svg>
                <div class="absolute text-center">
                    <div class="text-2xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $progress }}%</div>
                    <div class="text-[8px] font-bold uppercase tracking-wider text-zinc-400">Shift Completed</div>
                </div>
            </div>
        </div>
        <div class="mb-3 grid grid-cols-2 gap-2 text-center">
            <div class="rounded-xl bg-emerald-50/70 py-1.5"><div class="text-xs font-black tabular-nums text-emerald-700">{{ $workedLabel }}</div><div class="text-[8px] font-bold uppercase tracking-wider text-emerald-600/70">Worked</div></div>
            <div class="rounded-xl bg-orange-50/70 py-1.5"><div class="text-xs font-black tabular-nums text-orange-600">{{ intdiv($remainingMin,60) }}h {{ $remainingMin%60 }}m</div><div class="text-[8px] font-bold uppercase tracking-wider text-orange-500/70">Remaining</div></div>
        </div>

        <div class="space-y-1.5 text-xs">
            @foreach([
                ['Shift Start', $shift ? \Carbon\Carbon::parse($shift->start_time)->format('g:i A') : '—'],
                ['Shift End', $shift ? \Carbon\Carbon::parse($shift->end_time)->format('g:i A') : '—'],
                ['Expected Logout', $expectedLogout],
                ['Break Used', $breakMin.'m / '.(int) ($shift->break_duration ?? 60).'m'],
                ['Grace Time', ($shift->grace_minutes ?? 5).'m'],
                ['Overtime', intdiv($otMinTotal,60).'h '.($otMinTotal%60).'m'],
            ] as [$k, $v])
                <div class="flex items-center justify-between"><span class="text-zinc-400">{{ $k }}</span><span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $v }}</span></div>
            @endforeach
            <div class="flex items-center justify-between border-t border-zinc-100 dark:border-zinc-800 pt-2">
                <span class="text-zinc-400">Mode</span>
                <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-bold uppercase {{ $heroMode->chipClass() }}">{{ $heroMode->label() }}</span>
            </div>
        </div>
    </div>

    {{-- Biometric Status --}}
    @php
        // Connection tiers from sync recency: green <30m, orange <2h, red beyond.
        $syncAge = $lastSync ? (int) \Carbon\Carbon::parse($lastSync)->diffInMinutes(now()) : null;
        [$connBadge, $connDot, $connLabel, $bars] = match (true) {
            $syncAge !== null && $syncAge < 30 => ['bg-emerald-100 text-emerald-700', 'bg-emerald-500 animate-pulse', 'Online', 4],
            $syncAge !== null && $syncAge < 120 => ['bg-amber-100 text-amber-700', 'bg-amber-500 animate-pulse', 'Sync Delayed', 2],
            default => ['bg-rose-100 text-rose-600', 'bg-rose-500', 'Offline', 0],
        };
        $health = match (true) {
            $bars >= 4 && ($biometricDevice?->last_ping_status !== 'failed') => ['Healthy', 'text-emerald-600'],
            $bars >= 2 => ['Degraded', 'text-amber-600'],
            default => ['Unreachable', 'text-rose-500'],
        };
        $serial = $sum?->device_serial ?? collect($attendanceJourney)->pluck('device')->filter()->first() ?? '—';
        // Punch sources seen today: biometric methods + web + manual (approved correction).
        $sources = $punchMethods->map(fn ($m) => ['label' => $m->label(), 'icon' => $m->icon(), 'class' => $m->chipClass()])->values()->all();
        if ($todayAttendance?->check_in_user_agent) {
            $sources[] = ['label' => 'Web', 'icon' => 'computer-desktop', 'class' => 'bg-zinc-100 dark:bg-zinc-800 text-zinc-600 dark:text-zinc-300'];
        }
        if ($todayAttendance?->regularisation?->status === 'approved') {
            $sources[] = ['label' => 'Manual', 'icon' => 'pencil-square', 'class' => 'bg-zinc-800 text-white'];
        }
    @endphp
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-4">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Biometric Status</div>
            <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-[10px] font-bold {{ $connBadge }}"><span class="size-1.5 rounded-full {{ $connDot }}"></span>{{ $connLabel }}</span>
        </div>

        {{-- Device identity + signal --}}
        <div class="mb-3 flex items-center gap-3 rounded-2xl border border-orange-100 bg-gradient-to-br from-orange-50/60 to-white p-3">
            <div class="grid size-12 shrink-0 place-items-center rounded-xl bg-white dark:bg-zinc-900 shadow-sm"><flux:icon.finger-print class="size-6 text-orange-400" /></div>
            <div class="min-w-0 flex-1">
                <div class="truncate text-xs font-black text-zinc-900 dark:text-white">{{ $deviceName }}</div>
                <div class="truncate text-[10px] text-zinc-400">SN {{ $serial }}{{ $biometricDevice?->firmware ? ' · FW '.$biometricDevice->firmware : '' }} · {{ $emp?->office?->name ?? 'Head Office' }}</div>
            </div>
            {{-- Signal bars (sync freshness) --}}
            <div class="flex items-end gap-0.5" title="Sync freshness{{ $syncAge !== null ? ' · last '.$syncAge.'m ago' : '' }}">
                @foreach([1, 2, 3, 4] as $b)
                    <span class="w-1 rounded-sm {{ $b <= $bars ? 'bg-emerald-500' : 'bg-zinc-200' }}" style="height: {{ 4 + $b * 3 }}px"></span>
                @endforeach
            </div>
        </div>

        {{-- Stats --}}
        <div class="mb-3 grid grid-cols-2 gap-1.5 text-xs">
            @foreach([
                ['Last Sync', $lastSync ? \Carbon\Carbon::parse($lastSync)->format('h:i A') : '—'],
                ['Device Health', null],
                ["Today's Punches", $totalPunches ?: '—'],
                ['Successful', $totalPunches ?: '—'],
                ['Failed', $totalPunches ? '0 reported' : '—'],
                ['Last Sync Count', $biometricDevice?->last_sync_count ?? '—'],
            ] as [$k, $v])
                <div class="flex items-center justify-between rounded-lg bg-zinc-50/70 dark:bg-zinc-800/40 px-2.5 py-1.5">
                    <span class="text-zinc-400">{{ $k }}</span>
                    @if($k === 'Device Health')
                        <span class="font-bold {{ $health[1] }}">{{ $health[0] }}</span>
                    @else
                        <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $v }}</span>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Punch sources today --}}
        @if(! empty($sources))
            <div class="mb-3">
                <div class="mb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Punch Source</div>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($sources as $s)
                        <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $s['class'] }}"><flux:icon :icon="$s['icon']" class="size-3" /> {{ $s['label'] }}</span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Today's activity (punch ticks) --}}
        @if(count($attendanceJourney) > 0)
            <div class="mb-3">
                <div class="mb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Today's Activity</div>
                <div class="flex flex-wrap items-center gap-1">
                    @foreach($attendanceJourney as $jev)
                        @php $tickC = match($jev['type']) { 'in' => 'bg-emerald-500', 'out' => 'bg-rose-500', 'break' => 'bg-orange-400', 'resume' => 'bg-blue-400', default => 'bg-zinc-300' }; @endphp
                        <span class="size-2 rounded-full {{ $tickC }} transition-transform hover:scale-150" title="{{ $jev['time'] }} · {{ $jev['title'] }}"></span>
                        @unless($loop->last)<span class="h-px w-2 bg-zinc-200"></span>@endunless
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Sync history --}}
        @if(! empty($syncHistory))
            <div>
                <div class="mb-1.5 text-[9px] font-bold uppercase tracking-widest text-zinc-400">Sync History</div>
                <div class="space-y-1">
                    @foreach($syncHistory as $sh)
                        <div class="flex items-center justify-between rounded-lg px-2 py-1 text-[10px] odd:bg-orange-50/40">
                            <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $sh['date'] }}</span>
                            <span class="text-zinc-400">{{ $sh['punches'] }} punches</span>
                            <span class="inline-flex items-center gap-1 font-semibold text-emerald-600"><flux:icon.check class="size-3" /> {{ $sh['synced'] }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>

{{-- ═══════════════ ATTENDANCE ANALYTICS (enterprise grid) ═══════════════ --}}
@php
    $ck = $statsPeriod.'-'.$analyticsMode.'-'.($rangeFrom ?? '').'-'.($rangeTo ?? '');
    $axis = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
    $baseChart = fn (string $type, int $h) => ['type' => $type, 'height' => $h, 'toolbar' => ['show' => false], 'fontFamily' => 'inherit', 'animations' => ['enabled' => true, 'speed' => 700]];
    $labels = collect($chartDaily)->pluck('label')->all();
    $tick = min(8, max(1, count($chartDaily)));

    // 1 · Working Hours Trend — smooth line
    $hoursChart = [
        'chart' => $baseChart('line', 220),
        'colors' => ['#F97316'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'markers' => ['size' => 0, 'hover' => ['size' => 5]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours', 'data' => collect($chartDaily)->pluck('hours')->all()]],
    ];

    // 2 · Attendance Score Trend — area
    $scoreSeries = collect($chartDaily)->map(fn($d) => min(100, (int) round(((float) $d['hours']) / max(1, $stdHours) * 100)))->all();
    $scoreChart = [
        'chart' => $baseChart('area', 220),
        'colors' => ['#8b5cf6'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Score', 'data' => $scoreSeries]],
    ];

    // 3 · Weekly Attendance — Mon–Sun column, coloured by status
    $weekColors = collect($weekSummary)->map(fn ($wd) => match($wd['status']) {
        'present' => '#10b981', 'late' => '#f59e0b', 'leave' => '#8b5cf6', 'holiday' => '#3b82f6',
        'weekend' => '#d4d4d8', 'future' => '#e4e4e7', default => '#f43f5e',
    })->all();
    $weeklyChart = [
        'chart' => $baseChart('bar', 200),
        'colors' => $weekColors,
        'plotOptions' => ['bar' => ['borderRadius' => 5, 'columnWidth' => '55%', 'distributed' => true]],
        'dataLabels' => ['enabled' => false], 'legend' => ['show' => false],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($weekSummary)->pluck('label')->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Hours', 'data' => collect($weekSummary)->map(fn ($wd) => round((float) $wd['hours'], 1))->all()]],
    ];

    // 4 · Monthly Attendance — Present / Late / Absent donut
    $monthlyDonut = [
        'chart' => $baseChart('donut', 210),
        'labels' => ['On-time', 'Late', 'Absent'],
        'colors' => ['#10b981', '#f59e0b', '#ef4444'],
        'series' => [$onTimeCount, $lateCount, (int) ($stats['absent'] ?? 0)],
        'legend' => ['position' => 'bottom', 'fontSize' => '11px', 'labels' => ['colors' => '#9CA3AF']],
        'dataLabels' => ['enabled' => false], 'stroke' => ['width' => 0],
        'plotOptions' => ['pie' => ['donut' => ['size' => '70%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Days', 'fontSize' => '11px', 'color' => '#9CA3AF']]]]],
        'tooltip' => ['theme' => 'light'],
    ];

    // 5 · Late Arrival Trend — column (fixed 6-month window)
    $lateTrend = $analytics['late_trend'] ?? [];
    $lateChart = [
        'chart' => $baseChart('bar', 200),
        'colors' => ['#F59E0B'], 'plotOptions' => ['bar' => ['borderRadius' => 5, 'columnWidth' => '45%']],
        'dataLabels' => ['enabled' => false], 'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($lateTrend)->pluck('month')->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Late days', 'data' => collect($lateTrend)->pluck('late')->all()]],
    ];

    // 6 · Break Analysis — daily break minutes + average line
    $breakVals = collect($chartDaily)->pluck('break');
    $avgBreakLine = (int) ($analytics['avg_break'] ?? 0);
    $longBreaks = $breakVals->filter(fn ($b) => $b > 60)->count();
    $shortBreaks = $breakVals->filter(fn ($b) => $b > 0 && $b < 20)->count();
    $breakChart = [
        'chart' => $baseChart('bar', 200),
        'colors' => ['#0ea5e9'], 'plotOptions' => ['bar' => ['borderRadius' => 4, 'columnWidth' => '55%']],
        'dataLabels' => ['enabled' => false], 'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'annotations' => ['yaxis' => [['y' => $avgBreakLine, 'borderColor' => '#F97316', 'strokeDashArray' => 5, 'label' => ['text' => 'Avg '.$avgBreakLine.'m', 'style' => ['color' => '#F97316', 'background' => '#FFF7ED', 'fontSize' => '10px']]]]],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Break (min)', 'data' => $breakVals->all()]],
    ];

    // 7 · Office vs WFH vs Hybrid — horizontal bars
    $modeBreakdown = $analytics['mode_breakdown'] ?? [];
    $modeChart = [
        'chart' => $baseChart('bar', 200),
        'colors' => collect($modeBreakdown)->keys()->map(fn ($k) => AttendanceMode::tryFromValue($k)->hex())->all(),
        'plotOptions' => ['bar' => ['horizontal' => true, 'borderRadius' => 5, 'barHeight' => '55%', 'distributed' => true]],
        'dataLabels' => ['enabled' => true, 'style' => ['fontSize' => '11px']], 'legend' => ['show' => false],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => collect($modeBreakdown)->keys()->map(fn ($k) => AttendanceMode::tryFromValue($k)->label())->all()]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'Days', 'data' => collect($modeBreakdown)->values()->map(fn ($v) => (int) $v)->all()]],
    ];

    // 9 · Overtime Trend — daily hours beyond the standard day
    $otSeries = collect($chartDaily)->map(fn ($d) => round(max(0, (float) $d['hours'] - $stdHours), 1))->all();
    $otChart = [
        'chart' => $baseChart('area', 200),
        'colors' => ['#8b5cf6'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 2.5],
        'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.3, 'opacityTo' => 0.02, 'stops' => [0, 90]]],
        'grid' => ['borderColor' => '#F3E8DD', 'strokeDashArray' => 4],
        'xaxis' => array_merge($axis, ['categories' => $labels, 'tickAmount' => $tick]),
        'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '10px']]], 'tooltip' => ['theme' => 'light'],
        'series' => [['name' => 'OT hours', 'data' => $otSeries]],
    ];

    // 10 · Productivity Score — worked vs expected across the period
    $expectedMin = max(1, $totalWorkingDays * $stdHours * 60);
    $workedTotalMin = (int) round(collect($chartDaily)->sum('hours') * 60);
    $productivity = min(100, (int) round($workedTotalMin / $expectedMin * 100));
    $productivityChart = [
        'chart' => $baseChart('radialBar', 210),
        'colors' => ['#F97316'],
        'plotOptions' => ['radialBar' => [
            'hollow' => ['size' => '62%'],
            'track' => ['background' => '#FFEDD5'],
            'dataLabels' => ['name' => ['show' => true, 'fontSize' => '11px', 'color' => '#9CA3AF', 'offsetY' => 18], 'value' => ['fontSize' => '26px', 'fontWeight' => 800, 'color' => '#18181b', 'offsetY' => -12]],
        ]],
        'labels' => ['Productivity'],
        'series' => [$productivity],
    ];

    // 8 · Heatmap intensity (GitHub-style, CSS)
    $heatColor = fn ($h) => match (true) {
        $h <= 0 => 'bg-zinc-100 dark:bg-zinc-800',
        $h < 4 => 'bg-orange-200',
        $h < 7 => 'bg-orange-300',
        $h < $stdHours => 'bg-orange-400',
        default => 'bg-orange-600',
    };
@endphp

<div class="flex items-center justify-between">
    <div class="flex items-center gap-2">
        <span class="inline-flex size-8 items-center justify-center rounded-xl bg-orange-50 text-orange-500"><flux:icon.chart-bar class="size-4" /></span>
        <div class="text-sm font-black text-zinc-900 dark:text-white">Attendance Analytics</div>
        <span class="rounded-full bg-orange-50 px-2 py-0.5 text-[10px] font-bold text-orange-500">{{ ucwords(str_replace('_', ' ', $statsPeriod)) }}{{ $analyticsMode !== '' ? ' · '.AttendanceMode::tryFromValue($analyticsMode)->label() : '' }}</span>
    </div>
    @if($analyticsMode !== '')
        <button wire:click="$set('analyticsMode', '')" class="text-[11px] font-bold text-orange-500 hover:underline">Clear mode filter</button>
    @endif
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-12" wire:loading.class="opacity-50" wire:target="statsPeriod,analyticsMode,rangeFrom,rangeTo">
    {{-- Row 1 --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-5">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Working Hours Trend</div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$hoursChart" id="hours-chart" wire:key="hours-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[220px] items-center justify-center text-xs text-zinc-300">No data in this period.</div>@endif
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-3">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Monthly Attendance</div>
        @if($presentCount + ($stats['absent'] ?? 0) > 0)<x-dashboard.chart :options="$monthlyDonut" id="monthly-donut" wire:key="donut-{{ $ck }}" class="grid place-items-center" />@else<div class="flex h-[210px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
        <div class="mt-1 grid grid-cols-3 gap-1 text-center text-[9px] font-bold">
            <span class="text-emerald-600">{{ $attPct }}% Present</span>
            <span class="text-amber-600">{{ $presentCount > 0 ? round($lateCount / $presentCount * 100) : 0 }}% Late</span>
            <span class="text-rose-500">{{ $totalWorkingDays > 0 ? round(($stats['absent'] ?? 0) / $totalWorkingDays * 100) : 0 }}% Absent</span>
        </div>
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Attendance Score Trend</div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$scoreChart" id="score-chart" wire:key="score-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[220px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
    </div>

    {{-- Row 2 --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Weekly Attendance</div>
            <div class="flex gap-1.5 text-[8px] font-bold">
                <span class="text-emerald-600">● Present</span><span class="text-amber-600">● Late</span><span class="text-violet-600">● Leave</span><span class="text-blue-600">● Holiday</span>
            </div>
        </div>
        <x-dashboard.chart :options="$weeklyChart" id="weekly-chart" wire:key="weekly-{{ $ck }}" class="-mb-2" />
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Late Arrival Trend <span class="text-[10px] font-bold text-zinc-400">· 6 months</span></div>
        <x-dashboard.chart :options="$lateChart" id="late-chart" wire:key="late-{{ $ck }}" class="-mb-2" />
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Break Analysis</div>
            <div class="flex gap-2 text-[9px] font-bold text-zinc-400"><span>Avg <strong class="text-sky-600">{{ $avgBreakLine }}m</strong></span><span>Long <strong class="text-amber-600">{{ $longBreaks }}</strong></span><span>Short <strong class="text-emerald-600">{{ $shortBreaks }}</strong></span></div>
        </div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$breakChart" id="break-chart" wire:key="break-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
    </div>

    {{-- Row 3 --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-4">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Office vs WFH vs Hybrid</div>
        @if(! empty($modeBreakdown))<x-dashboard.chart :options="$modeChart" id="mode-chart" wire:key="mode-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No attendance yet.</div>@endif
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-5">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Overtime Trend</div>
        @if(count($chartDaily) > 0)<x-dashboard.chart :options="$otChart" id="ot-chart" wire:key="ot-{{ $ck }}" class="-mb-2" />@else<div class="flex h-[200px] items-center justify-center text-xs text-zinc-300">No data.</div>@endif
    </div>
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-3">
        <div class="mb-1 text-sm font-black text-zinc-900 dark:text-white">Productivity Score</div>
        <x-dashboard.chart :options="$productivityChart" id="productivity-chart" wire:key="prod-{{ $ck }}" class="grid place-items-center" />
        <div class="text-center text-[10px] text-zinc-400">worked vs expected ({{ $totalWorkingDays }} working days × {{ $stdHours }}h)</div>
    </div>

    {{-- Row 4 · Heatmap --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm transition hover:shadow-md lg:col-span-12">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Attendance Heatmap <span class="text-[10px] font-bold text-zinc-400">· hours per day</span></div>
            <div class="flex items-center gap-1 text-[10px] text-zinc-400">Less
                <span class="size-2.5 rounded-sm bg-zinc-100 dark:bg-zinc-800"></span><span class="size-2.5 rounded-sm bg-orange-200"></span><span class="size-2.5 rounded-sm bg-orange-400"></span><span class="size-2.5 rounded-sm bg-orange-600"></span>
            More</div>
        </div>
        @if(count($chartDaily) > 0)
            <div class="flex flex-wrap gap-1.5">
                @foreach($chartDaily as $d)
                    <div class="size-6 rounded-md {{ $heatColor((float) $d['hours']) }} transition-transform hover:scale-125"
                        title="{{ $d['label'] }} · {{ $d['hours'] }}h{{ $d['late'] ? ' · late' : '' }}"></div>
                @endforeach
            </div>
        @else
            <div class="py-6 text-center text-xs text-zinc-300">No data for this period.</div>
        @endif
    </div>
</div>

{{-- ═══════════════ AI ATTENDANCE INSIGHTS ═══════════════ --}}
@if(! empty($insightStats))
    @php
        $ai = $insightStats;
        $trendChip = function ($delta, bool $goodWhenUp = true) {
            if ($delta === null || $delta === 0) { return null; }
            $up = $delta > 0;
            $good = $up === $goodWhenUp;

            return ['icon' => $up ? 'arrow-trending-up' : 'arrow-trending-down',
                'class' => $good ? 'text-emerald-600' : 'text-rose-500',
                'text' => ($up ? '+' : '').$delta.' vs prev'];
        };
        $aiCards = [
            ['Attendance Score', $ai['score'].'/100', 'shield-check', '#10b981', $trendChip($ai['present_trend'])],
            ['Average Check-in', $ai['avg_in'] ?? '—', 'arrow-right-end-on-rectangle', '#F97316', null],
            ['Average Check-out', $ai['avg_out'] ?? '—', 'arrow-left-start-on-rectangle', '#ef4444', null],
            ['Average Break', $ai['avg_break'] ? $ai['avg_break'].'m' : '—', 'pause', '#0ea5e9', null],
            ['Avg Working Hours', $ai['avg_hours'] ?? '—', 'clock', '#6366f1', null],
            ['Best Attendance Day', $ai['best_day'] ?? '—', 'star', '#f59e0b', null],
            ['Longest Working Day', $ai['longest_day'] ?? '—', 'bolt', '#8b5cf6', null],
            ['Longest Break', $ai['longest_break'] ?? '—', 'moon', '#14b8a6', null],
            ['Perfect Streak', $ai['streak'].' '.\Illuminate\Support\Str::plural('day', $ai['streak']), 'fire', '#f97316', null],
            ['Late Count', $ai['late_count'], 'exclamation-triangle', $ai['late_count'] ? '#f59e0b' : '#10b981', $trendChip($ai['late_trend'], false)],
            ['Missing Punches', $ai['missing_count'], 'flag', $ai['missing_count'] ? '#ef4444' : '#10b981', null],
            ['Attendance Prediction', $ai['prediction'].'%', 'sparkles', '#F97316', null],
        ];
    @endphp
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex size-8 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-amber-400 text-white shadow"><flux:icon.sparkles class="size-4" /></span>
                <div class="text-sm font-black text-zinc-900 dark:text-white">AI Attendance Insights</div>
                <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">· {{ ucwords(str_replace('_', ' ', $statsPeriod)) }}</span>
            </div>
            <span class="rounded-full bg-orange-50 px-2.5 py-1 text-[10px] font-bold text-orange-500">Predicted {{ $ai['prediction'] }}% by period end</span>
        </div>

        {{-- Stat cards --}}
        <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 xl:grid-cols-6">
            @foreach($aiCards as [$label, $value, $icon, $color, $trend])
                <div class="rounded-2xl border border-zinc-100 dark:border-zinc-800 bg-gradient-to-b from-white to-orange-50/30 p-3 transition duration-200 hover:-translate-y-0.5 hover:shadow-md">
                    <span class="inline-flex size-8 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-4" /></span>
                    <div class="mt-2 truncate text-base font-black leading-none tabular-nums text-zinc-900 dark:text-white" title="{{ $value }}">{{ $value }}</div>
                    <div class="mt-1 truncate text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                    @if($trend)
                        <div class="mt-0.5 flex items-center gap-1 text-[9px] font-bold {{ $trend['class'] }}"><flux:icon :icon="$trend['icon']" class="size-3" /> {{ $trend['text'] }}</div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Suggestions --}}
        @if(! empty($ai['suggestions']))
            <div class="mt-4 border-t border-orange-100/70 pt-3">
                <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Suggestions</div>
                <div class="flex flex-wrap gap-2">
                    @foreach($ai['suggestions'] as $s)
                        <span class="inline-flex items-center gap-1.5 rounded-full {{ $s['good'] ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' }} px-3 py-1.5 text-xs font-semibold">
                            <flux:icon :icon="$s['good'] ? 'check-circle' : 'light-bulb'" class="size-3.5 {{ $s['good'] ? 'text-emerald-500' : 'text-amber-500' }}" /> {{ $s['text'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Detailed observations (existing generated insights) --}}
        @if(count($insights) > 0)
            <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3">
                @foreach($insights as $ins)
                    <div class="flex items-center gap-2 rounded-xl {{ $ins['good'] ? 'bg-emerald-50/60' : 'bg-amber-50/60' }} px-3 py-2 text-xs">
                        <flux:icon :icon="$ins['good'] ? 'check-circle' : 'exclamation-circle'" class="size-4 shrink-0 {{ $ins['good'] ? 'text-emerald-500' : 'text-amber-500' }}" />
                        <span class="font-semibold {{ $ins['good'] ? 'text-emerald-800' : 'text-amber-800' }}">{{ $ins['text'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
@endif

{{-- ═══════════════ WFH DAILY REPORT (WFH/Hybrid days only) ═══════════════ --}}
@if(in_array($heroMode->value, ['wfh', 'hybrid'], true))
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm">
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center gap-2"><span class="inline-flex size-8 items-center justify-center rounded-xl bg-violet-50 text-violet-600"><flux:icon.home class="size-4" /></span><div class="text-sm font-black text-zinc-900 dark:text-white">WFH Daily Report</div></div>
            @if($wfhReport)<span class="inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2.5 py-1 text-[10px] font-bold text-emerald-700"><flux:icon.check class="size-3" /> Submitted</span>@endif
        </div>
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <div class="md:col-span-2">
                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">What did you work on today? <span class="text-rose-400">*</span></label>
                <textarea wire:model="wfhForm.work_summary" rows="2" placeholder="Tasks, tickets, meetings…" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea>
                @error('wfhForm.work_summary')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div><label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">Achievements</label><textarea wire:model="wfhForm.achievements" rows="2" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea></div>
            <div><label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">Blockers</label><textarea wire:model="wfhForm.blockers" rows="2" class="w-full rounded-xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 px-3 py-2 text-sm focus:border-orange-400 focus:ring-0"></textarea></div>
        </div>
        <div class="mt-3 flex justify-end">
            <button wire:click="saveWfhReport" class="inline-flex items-center gap-1.5 rounded-xl bg-violet-600 px-5 py-2 text-sm font-bold text-white transition hover:bg-violet-700"><flux:icon.paper-airplane class="size-4" /> {{ $wfhReport ? 'Update Report' : 'Submit Report' }}</button>
        </div>
    </div>
@endif

{{-- ═══════════════ PUNCH IN / OUT TIMELINE ═══════════════ --}}
<div id="attendance-log" class="overflow-hidden rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 shadow-sm scroll-mt-6">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-orange-100/70 px-5 py-3.5">
        <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white"><flux:icon.clock class="size-4 text-orange-500" /> Punch In / Out Timeline
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">
                · {{ $statsPeriod === 'custom' && $rangeFrom && $rangeTo ? \Carbon\Carbon::parse($rangeFrom)->format('d M').' – '.\Carbon\Carbon::parse($rangeTo)->format('d M Y') : $calendarMonth->format('M Y') }}
            </span>
        </h3>
        <div class="flex flex-wrap items-center gap-2">
            <x-clean-select model="logMode" :live="true"
                :options="[['value' => '', 'label' => 'All modes'], ...collect(AttendanceMode::cases())->map(fn ($mode) => ['value' => $mode->value, 'label' => $mode->label()])->all()]" />
            <button wire:click="exportLog" class="inline-flex items-center gap-1.5 rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-bold text-white transition hover:bg-orange-600"><flux:icon.arrow-down-tray class="size-3.5" /> Export</button>
        </div>
    </div>

    @php $days = $logMode !== '' ? collect($logTimeline)->where('mode', $logMode)->values() : collect($logTimeline); @endphp
    @if($days->count() > 0)
        <div class="divide-y divide-orange-50">
            @foreach($days as $day)
                @php
                    [$dayBadge, $dayLabel] = match(true) {
                        $day['is_late'] => ['bg-amber-50 text-amber-600', 'Late'],
                        $day['status'] === 'on_time' => ['bg-emerald-50 text-emerald-600', 'Present'],
                        default => ['bg-zinc-50 dark:bg-zinc-800/50 text-zinc-500 dark:text-zinc-400', ucfirst($day['status'] ?? '—')],
                    };
                    $dayMode = AttendanceMode::tryFromValue($day['mode'] ?? 'office');
                @endphp
                <div x-data="{ open: {{ $day['is_today'] ? 'true' : 'false' }} }" class="{{ $day['missing'] ? 'bg-amber-50/30' : '' }}">
                    {{-- Day header --}}
                    <button type="button" @click="open = !open" class="flex w-full flex-wrap items-center gap-3 px-5 py-3 text-left transition hover:bg-orange-50/40">
                        <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400 transition-transform" ::class="open ? 'rotate-90' : ''" />
                        <div class="min-w-[7.5rem]">
                            <div class="text-xs font-black text-zinc-900 dark:text-white">{{ $day['label'] }} @if($day['is_today'])<span class="text-orange-500">· Today</span>@endif</div>
                            <div class="text-[9px] text-zinc-400">{{ $day['dayname'] }}</div>
                        </div>
                        <span class="rounded-full px-2 py-0.5 text-[9px] font-bold {{ $dayBadge }}">{{ $dayLabel }}</span>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[8px] font-bold uppercase {{ $dayMode->chipClass() }}">{{ $dayMode->shortLabel() }}</span>
                        @if($day['reg_status'])
                            @php $regC = match($day['reg_status']) { 'approved' => 'bg-blue-50 text-blue-600', 'rejected' => 'bg-rose-50 text-rose-500', default => 'bg-amber-50 text-amber-600' }; @endphp
                            <span class="rounded-full px-2 py-0.5 text-[8px] font-bold uppercase {{ $regC }}">{{ $day['reg_status'] === 'approved' ? 'Regularized' : 'Reg. '.$day['reg_status'] }}</span>
                        @endif
                        <span class="ml-auto flex items-center gap-3 text-[10px] text-zinc-400">
                            <span>{{ count($day['events']) }} {{ \Illuminate\Support\Str::plural('punch', count($day['events'])) }}</span>
                            @if($day['worked'])<span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $day['worked'] }}</span>@endif
                            <span class="rounded-lg p-1 text-zinc-400 transition hover:bg-orange-100 hover:text-orange-600" wire:click.stop="showPunchDetail('{{ $day['date'] }}')" title="Full day details"><flux:icon.eye class="size-4" /></span>
                        </span>
                    </button>

                    <div x-show="open" x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="-translate-y-1 opacity-0" x-transition:enter-end="translate-y-0 opacity-100" class="px-5 pb-4">
                        {{-- Missing punch banner --}}
                        @if($day['missing'])
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2 rounded-xl border border-amber-300 bg-gradient-to-r from-amber-50 to-orange-50 px-3 py-2">
                                <div class="flex items-center gap-2 text-xs">
                                    <flux:icon.exclamation-triangle class="size-4 text-amber-500" />
                                    <span class="font-black text-amber-900">Missing Clock Out</span>
                                    @if($day['worked'])<span class="text-amber-700">· Worked {{ $day['worked'] }}</span>@endif
                                </div>
                                <button wire:click="openRegularisation('{{ $day['date'] }}')" class="inline-flex items-center gap-1 rounded-lg bg-amber-500 px-3 py-1 text-[11px] font-bold text-white transition hover:bg-amber-600"><flux:icon.pencil-square class="size-3" /> Request Regularization</button>
                            </div>
                        @endif

                        {{-- Vertical punch timeline --}}
                        @if(count($day['events']) > 0)
                            <div class="relative ml-2 space-y-2.5">
                                @foreach($day['events'] as $ev)
                                    @php
                                        [$dot, $ic] = match($ev['type']) {
                                            'in'     => ['bg-emerald-500', 'arrow-right-end-on-rectangle'],
                                            'late'   => ['bg-rose-500', 'exclamation-triangle'],
                                            'break'  => ['bg-orange-500', 'pause'],
                                            'resume' => ['bg-blue-500', 'play'],
                                            'out'    => ['bg-rose-600', 'arrow-left-start-on-rectangle'],
                                            default  => ['bg-zinc-400', 'clock'],
                                        };
                                        $evMethod = PunchMethod::tryFrom((string) ($ev['method'] ?? ''));
                                        $srcLabel = $evMethod?->label() ?? match($ev['source'] ?? '') { 'web' => 'Web Punch', 'mobile' => 'Mobile GPS', 'manual' => 'Manual Approval', default => 'Biometric' };
                                        $hasHover = ! empty($ev['lat']) || ! empty($ev['ip']) || ! empty($ev['photo']) || ! empty($ev['device']);
                                    @endphp
                                    <div class="group/event relative flex items-start gap-3">
                                        @unless($loop->last)<span class="absolute left-[13px] top-8 h-[calc(100%-6px)] w-px bg-orange-100"></span>@endunless
                                        <span class="mt-0.5 inline-flex size-7 shrink-0 items-center justify-center rounded-full {{ $dot }} text-white shadow"><flux:icon :icon="$ic" class="size-3.5" /></span>
                                        <div class="flex-1 rounded-xl border border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-800/40 px-3 py-2 transition group-hover/event:border-orange-200 group-hover/event:bg-white group-hover/event:shadow-sm">
                                            <div class="flex flex-wrap items-center gap-x-3 gap-y-0.5">
                                                <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $ev['time'] }}</span>
                                                @if($evMethod)
                                                    <span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[9px] font-bold {{ $evMethod->chipClass() }}"><flux:icon :icon="$evMethod->icon()" class="size-3" /> {{ $evMethod->label() }}</span>
                                                @else
                                                    <span class="text-[10px] font-bold text-zinc-500 dark:text-zinc-400">{{ $srcLabel }}</span>
                                                @endif
                                                <span class="text-[10px] text-zinc-400">{{ $ev['title'] }}</span>
                                                <span class="ml-auto inline-flex items-center gap-1 rounded-full bg-emerald-100 px-2 py-0.5 text-[9px] font-bold text-emerald-700">Success</span>
                                            </div>
                                            <div class="mt-0.5 flex flex-wrap items-center gap-x-3 text-[10px] text-zinc-400">
                                                @if(! empty($ev['device']) && is_string($ev['device']))<span class="inline-flex items-center gap-0.5"><flux:icon.cpu-chip class="size-3" /> {{ $ev['device'] }}</span>@endif
                                                @if(! empty($ev['location']))<span class="inline-flex items-center gap-0.5"><flux:icon.map-pin class="size-3" /> {{ $ev['location'] }}</span>@endif
                                            </div>
                                            @if($hasHover)
                                                <div class="mt-1.5 hidden flex-wrap items-center gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-1.5 text-[10px] text-zinc-500 dark:text-zinc-400 group-hover/event:flex">
                                                    @if(! empty($ev['lat']) && ! empty($ev['lng']))
                                                        <a href="https://www.google.com/maps?q={{ $ev['lat'] }},{{ $ev['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 font-semibold text-orange-500 hover:underline"><flux:icon.map-pin class="size-3" /> {{ $ev['lat'] }}, {{ $ev['lng'] }}</a>
                                                    @endif
                                                    @if(! empty($ev['ip']))<span class="inline-flex items-center gap-1"><flux:icon.globe-alt class="size-3" /> {{ $ev['ip'] }}</span>@endif
                                                    @if(! empty($ev['photo']))
                                                        <a href="{{ Storage::url($ev['photo']) }}" target="_blank" class="inline-flex items-center gap-1 font-semibold text-orange-500 hover:underline"><flux:icon.camera class="size-3" /> Photo verification</a>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-3 text-center text-xs text-zinc-400">No punches recorded for this day.</div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="py-12 text-center text-sm text-zinc-400"><flux:icon.clock class="mx-auto mb-2 size-8 opacity-30" /> No records for {{ $calendarMonth->format('F Y') }}</div>
    @endif

    {{-- Today's Summary footer --}}
    @if($todayAttendance)
        @php
            $firstPunch = $todayAttendance->check_in ?? $sum?->first_punch;
            $lastPunch = $todayAttendance->check_out ?? $sum?->last_punch;
        @endphp
        <div class="grid grid-cols-3 gap-2 border-t border-orange-100/70 bg-orange-50/30 px-5 py-3 sm:grid-cols-6">
            @foreach([
                ['Working Hours', $workedLabel],
                ['Break Time', intdiv($breakMin, 60) > 0 ? intdiv($breakMin, 60).'h '.($breakMin % 60).'m' : $breakMin.'m'],
                ['Overtime', intdiv($otMinTotal, 60).'h '.($otMinTotal % 60).'m'],
                ['Total Punches', $totalPunches ?: count($attendanceJourney) ?: '—'],
                ['First Punch', $firstPunch ? \Carbon\Carbon::parse($firstPunch)->format('h:i A') : '—'],
                ['Last Punch', $lastPunch ? \Carbon\Carbon::parse($lastPunch)->format('h:i A') : '—'],
            ] as [$k, $v])
                <div class="text-center">
                    <div class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $v }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">{{ $k }}</div>
                </div>
            @endforeach
        </div>
    @endif
    <div class="border-t border-orange-100/70 px-5 py-2 text-center text-[11px] text-zinc-400">All times are based on your shift timezone (IST)</div>
</div>

</div>{{-- end spacing wrapper --}}

{{-- ═══════════════════════════════════════════════
     PUNCH DETAIL MODAL
═══════════════════════════════════════════════ --}}
<flux:modal name="punch-detail" class="max-w-lg">
    @if($detail)
        <div class="space-y-4">
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Punch Details</flux:heading>
                    <flux:subheading>{{ $detail['date'] }}</flux:subheading>
                </div>
                @php $dMode = AttendanceMode::tryFromValue($detail['mode']); @endphp
                <span class="inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-[11px] font-bold {{ $dMode->chipClass() }}">
                    <flux:icon :icon="$dMode->icon()" class="size-3.5" /> {{ $dMode->label() }}
                </span>
            </div>

            <div class="grid grid-cols-4 gap-2 text-center">
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['total_hours'] ?? '—' }}<span class="text-[10px] text-zinc-400">h</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Worked</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['break_minutes'] }}<span class="text-[10px] text-zinc-400">m</span></div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Break</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black {{ $detail['is_late'] ? 'text-amber-600' : 'text-emerald-600' }}">{{ $detail['is_late'] ? 'Late' : 'On Time' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Status</div>
                </div>
                <div class="rounded-xl bg-zinc-50 dark:bg-zinc-800/50 p-2.5 dark:bg-zinc-800/40">
                    <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $detail['is_late'] ? $detail['late_minutes'].'m' : '—' }}</div>
                    <div class="text-[9px] font-bold uppercase tracking-wider text-zinc-400">Late by</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach(['in' => 'Check In', 'out' => 'Check Out'] as $key => $title)
                    @php $p = $detail[$key]; @endphp
                    <div class="rounded-2xl border border-zinc-200 dark:border-zinc-800 p-4 dark:border-zinc-800">
                        <div class="mb-2 flex items-center justify-between">
                            <span class="text-[10px] font-bold uppercase tracking-widest {{ $key === 'in' ? 'text-emerald-600' : 'text-zinc-500 dark:text-zinc-400' }}">{{ $title }}</span>
                            <span class="text-sm font-black tabular-nums text-zinc-900 dark:text-white">{{ $p['time'] ?? '—' }}</span>
                        </div>
                        @if($p['photo'])
                            <a href="{{ Storage::url($p['photo']) }}" target="_blank">
                                <img src="{{ Storage::url($p['photo']) }}" alt="Punch selfie" class="mb-2 h-24 w-full rounded-xl object-cover ring-1 ring-zinc-200 dark:ring-zinc-700">
                            </a>
                        @endif
                        <div class="space-y-1.5 text-[11px]">
                            @if($p['method'])
                                <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$p['method_icon']" class="size-3.5 text-zinc-400" /> {{ $p['method'] }}</div>
                            @endif
                            <div class="flex items-center gap-1.5 text-zinc-600 dark:text-zinc-300"><flux:icon.computer-desktop class="size-3.5 text-zinc-400" /> {{ $p['device'] }}</div>
                            @if($p['ip'])
                                <div class="flex items-center gap-1.5 text-zinc-500 dark:text-zinc-400"><flux:icon.globe-alt class="size-3.5 text-zinc-400" /> {{ $p['ip'] }}</div>
                            @endif
                            @if($p['lat'] && $p['lng'])
                                <a href="https://www.google.com/maps?q={{ $p['lat'] }},{{ $p['lng'] }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 font-semibold text-orange-500 hover:underline"><flux:icon.map-pin class="size-3.5" /> View location</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Attendance Replay — every raw punch of the day --}}
            @if(! empty($detail['punches']))
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Punch Timeline · {{ count($detail['punches']) }} punches</div>
                    <div class="max-h-48 space-y-1.5 overflow-y-auto pr-1">
                        @foreach($detail['punches'] as $i => $pp)
                            <div class="flex items-center gap-2 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 px-3 py-1.5 text-xs dark:bg-zinc-800/40">
                                <span class="w-16 shrink-0 font-black tabular-nums text-zinc-900 dark:text-white">{{ $pp['time'] }}</span>
                                @if($pp['method'])<span class="inline-flex items-center gap-1 text-zinc-600 dark:text-zinc-300"><flux:icon :icon="$pp['method_icon']" class="size-3.5 text-zinc-400" /> {{ $pp['method'] }}</span>@endif
                                <span class="ml-auto flex items-center gap-2 text-[10px] text-zinc-400">
                                    @if($pp['location'])<span>{{ $pp['location'] }}</span>@endif
                                    @if($pp['device'])<span>{{ $pp['device'] }}</span>@endif
                                    <span class="uppercase">{{ $pp['source'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Audit History — regularisations touching this day --}}
            @if(! empty($detail['audits']))
                <div>
                    <div class="mb-2 text-[10px] font-bold uppercase tracking-widest text-zinc-400">Audit History</div>
                    <div class="space-y-2">
                        @foreach($detail['audits'] as $audit)
                            @php
                                $ac = match($audit['status']) { 'approved' => 'bg-emerald-100 text-emerald-700', 'rejected' => 'bg-rose-100 text-rose-600', default => 'bg-amber-100 text-amber-700' };
                            @endphp
                            <div class="rounded-xl border border-zinc-100 dark:border-zinc-800 p-3 text-xs dark:border-zinc-800">
                                <div class="flex items-center justify-between">
                                    <span class="font-bold text-zinc-800 dark:text-zinc-100">Corrected to {{ $audit['requested_in'] }} → {{ $audit['requested_out'] }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {{ $ac }}">{{ $audit['status'] }}</span>
                                </div>
                                <div class="mt-1 text-zinc-500 dark:text-zinc-400">“{{ $audit['reason'] }}”</div>
                                <div class="mt-1 text-[10px] text-zinc-400">
                                    Submitted {{ $audit['submitted_at'] }}
                                    @if($audit['reviewer']) · {{ ucfirst($audit['status']) }} by {{ $audit['reviewer'] }} on {{ $audit['reviewed_at'] }}@endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex justify-end pt-1">
                <flux:button @click="$flux.modal('punch-detail').close()">Close</flux:button>
            </div>
        </div>
    @endif
</flux:modal>

{{-- ═══════════════════════════════════════════════
     REGULARISATION MODAL
═══════════════════════════════════════════════ --}}
<flux:modal name="regularisation-modal" class="max-w-md">
    <div class="space-y-5">
        <div>
            <flux:heading size="lg">Regularisation Request</flux:heading>
            <flux:subheading>Request a correction for a missing or wrong punch. HR approval auto-updates your hours &amp; attendance.</flux:subheading>
        </div>
        <div class="space-y-4">
            <flux:input wire:model="regDate" label="Date of Work" type="date" />

            <div>
                <div class="mb-2 text-sm font-medium text-zinc-700 dark:text-zinc-200">What needs correcting?</div>
                <div class="grid grid-cols-2 gap-3">
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm font-semibold transition {{ $regFixIn ? 'border-orange-400 bg-orange-50 text-orange-700' : 'border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400' }}">
                        <input type="checkbox" wire:model.live="regFixIn" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                        Check-In missed / wrong
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 rounded-xl border p-3 text-sm font-semibold transition {{ $regFixOut ? 'border-orange-400 bg-orange-50 text-orange-700' : 'border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400' }}">
                        <input type="checkbox" wire:model.live="regFixOut" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                        Check-Out missed / wrong
                    </label>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="{{ $regFixIn ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:input wire:model="regCheckIn" label="Correct Check-in" type="time" :disabled="! $regFixIn" />
                </div>
                <div class="{{ $regFixOut ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:input wire:model="regCheckOut" label="Correct Check-out" type="time" :disabled="! $regFixOut" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div class="{{ $regFixIn ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:select wire:model="regCheckInMethod" label="Check-in via" :disabled="! $regFixIn">
                        <flux:select.option value="id_card">ID Card</flux:select.option>
                        <flux:select.option value="face">Face</flux:select.option>
                    </flux:select>
                </div>
                <div class="{{ $regFixOut ? '' : 'pointer-events-none opacity-40' }}">
                    <flux:select wire:model="regCheckOutMethod" label="Check-out via" :disabled="! $regFixOut">
                        <flux:select.option value="id_card">ID Card</flux:select.option>
                        <flux:select.option value="face">Face</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <p class="text-[11px] text-zinc-400">Unticked punches keep their recorded time. On approval, working hours, overtime, score and payroll update automatically — and the corrected punch appears in your Attendance Journey with the method you pick above.</p>

            <flux:textarea wire:model="regReason" label="Reason" placeholder="e.g. Forgot to clock out, system glitch..." rows="3" />
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <flux:button @click="$flux.modal('regularisation-modal').close()">Cancel</flux:button>
            <flux:button wire:click="submitRegularisation" variant="primary">Send to Manager &amp; HR</flux:button>
        </div>
    </div>
</flux:modal>

{{-- ═══════════════════════════════════════════════
     PUNCH CAPTURE MODAL — selfie + geolocation
═══════════════════════════════════════════════ --}}
<flux:modal name="punch-capture" class="max-w-md"
    x-data="{
        action: 'in', lat: null, lng: null, photo: null,
        stream: null, status: 'idle', geoStatus: 'pending', busy: false,
        async openCapture(action) {
            this.action = action; this.photo = null; this.lat = null; this.lng = null;
            this.geoStatus = 'pending'; this.busy = false;
            this.getLocation();
            await this.startCamera();
        },
        getLocation() {
            if (! ('geolocation' in navigator)) { this.geoStatus = 'unavailable'; return; }
            navigator.geolocation.getCurrentPosition(
                p => { this.lat = +p.coords.latitude.toFixed(6); this.lng = +p.coords.longitude.toFixed(6); this.geoStatus = 'ok'; },
                () => { this.geoStatus = 'denied'; },
                { enableHighAccuracy: true, timeout: 8000, maximumAge: 60000 }
            );
        },
        async startCamera() {
            if (! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia) { this.status = 'nocamera'; return; }
            try {
                this.stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false });
                this.status = 'camera';
                this.$nextTick(() => { if (this.$refs.video) this.$refs.video.srcObject = this.stream; });
            } catch (e) { this.status = 'nocamera'; }
        },
        capture() {
            const v = this.$refs.video, c = this.$refs.canvas;
            if (! v) return;
            const w = 360, h = Math.round(w * (v.videoHeight || 480) / (v.videoWidth || 640));
            c.width = w; c.height = h;
            c.getContext('2d').drawImage(v, 0, 0, w, h);
            this.photo = c.toDataURL('image/jpeg', 0.7);
            this.stopCamera(); this.status = 'preview';
        },
        retake() { this.photo = null; this.startCamera(); },
        stopCamera() { if (this.stream) { this.stream.getTracks().forEach(t => t.stop()); this.stream = null; } },
        cleanup() { this.stopCamera(); this.status = 'idle'; this.busy = false; },
        async submit() {
            if (this.busy) return;
            this.busy = true;
            try {
                if (this.action === 'in') { await this.$wire.checkIn(this.lat, this.lng, this.photo); }
                else { await this.$wire.checkOut(this.lat, this.lng, this.photo); }
            } finally {
                this.cleanup();
                this.$flux.modal('punch-capture').close();
            }
        }
    }"
    x-on:open-punch.window="openCapture($event.detail.action)"
    x-on:close="cleanup()">
    <div class="space-y-4">
        <div>
            <flux:heading size="lg" x-text="action === 'in' ? 'Clock In' : 'End Work Day'">Clock In</flux:heading>
            <flux:subheading>Confirm with a quick selfie &amp; your location.</flux:subheading>
        </div>

        <div class="relative aspect-[4/3] w-full overflow-hidden rounded-2xl bg-zinc-900">
            <video x-ref="video" autoplay playsinline muted x-show="status === 'camera'" class="h-full w-full object-cover"></video>
            <img :src="photo" x-show="status === 'preview' && photo" class="h-full w-full object-cover" alt="Selfie preview">
            <div x-show="status === 'idle'" class="absolute inset-0 flex items-center justify-center text-zinc-500 dark:text-zinc-400">
                <flux:icon.camera class="size-9 animate-pulse" />
            </div>
            <div x-show="status === 'nocamera'" class="absolute inset-0 flex flex-col items-center justify-center px-6 text-center text-zinc-400">
                <flux:icon.video-camera-slash class="mb-2 size-9" />
                <p class="text-xs">Camera unavailable — you can still clock in without a photo.</p>
            </div>
            <canvas x-ref="canvas" class="hidden"></canvas>
        </div>

        <div class="flex items-center gap-2 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-3 py-2 text-xs dark:bg-zinc-800/50">
            <flux:icon.map-pin class="size-4 shrink-0"
                ::class="geoStatus === 'ok' ? 'text-emerald-500' : (geoStatus === 'pending' ? 'text-zinc-400 animate-pulse' : 'text-amber-500')" />
            <span x-show="geoStatus === 'pending'" class="text-zinc-400">Getting your location…</span>
            <span x-show="geoStatus === 'ok'" class="font-semibold text-emerald-600 dark:text-emerald-400" x-text="'Location captured · ' + lat + ', ' + lng"></span>
            <span x-show="geoStatus === 'denied'" class="text-amber-600 dark:text-amber-400">Location off — clocking in without it.</span>
            <span x-show="geoStatus === 'unavailable'" class="text-amber-600 dark:text-amber-400">Location unavailable on this device.</span>
        </div>

        <div class="flex items-center justify-between gap-2 pt-1">
            <button type="button" @click="cleanup(); $flux.modal('punch-capture').close()"
                class="rounded-xl px-4 py-2 text-sm font-bold text-zinc-500 dark:text-zinc-400 transition hover:bg-zinc-100 dark:bg-zinc-800 dark:hover:bg-zinc-800">Cancel</button>
            <div class="flex items-center gap-2">
                <button type="button" x-show="status === 'preview'" @click="retake()"
                    class="rounded-xl bg-zinc-100 dark:bg-zinc-800 px-4 py-2 text-sm font-bold text-zinc-600 dark:text-zinc-300 transition hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-200">Retake</button>
                <button type="button" x-show="status === 'camera'" @click="capture()"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-zinc-800 px-4 py-2 text-sm font-bold text-white transition hover:bg-zinc-900 dark:bg-zinc-700">
                    <flux:icon.camera class="size-4" /> Capture
                </button>
                <button type="button" @click="submit()" x-bind:disabled="busy"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-5 py-2 text-sm font-bold text-white shadow-lg shadow-orange-300/40 transition hover:bg-orange-600 disabled:opacity-50"
                    x-text="busy ? 'Saving…' : (action === 'in' ? 'Clock In' : 'Clock Out')">Clock In</button>
            </div>
        </div>
        <p class="text-center text-[10px] text-zinc-400">Photo &amp; location are optional — you can clock in without them.</p>
    </div>
</flux:modal>

</flux:main>
