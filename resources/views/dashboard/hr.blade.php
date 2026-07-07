<flux:main class="min-h-screen space-y-6 bg-[#FFFDF8] dark:bg-[#0B1220] p-4 font-['Inter'] md:p-6">
    <style>@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');</style>

    @php
        $r = fn ($n) => \Illuminate\Support\Facades\Route::has($n) ? route($n) : '#';
        $hour = now()->hour;
        $greeting = $hour < 12 ? 'Good Morning' : ($hour < 17 ? 'Good Afternoon' : 'Good Evening');
        $firstName = \Illuminate\Support\Str::of(auth()->user()->name)->explode(' ')->first();
        $today = now();

        $toneSoft = ['orange' => 'bg-orange-50 text-orange-500', 'green' => 'bg-green-50 text-green-600', 'amber' => 'bg-amber-50 text-amber-600', 'blue' => 'bg-blue-50 text-blue-600', 'red' => 'bg-red-50 text-red-600', 'violet' => 'bg-violet-50 text-violet-600'];
        $toneText = ['orange' => 'text-orange-500', 'green' => 'text-green-600', 'amber' => 'text-amber-600', 'blue' => 'text-blue-600', 'red' => 'text-red-600', 'violet' => 'text-violet-600'];

        $spark = function (float $end, float $swing = 0.16) {
            $pts = [];
            $base = max($end, 1);
            for ($i = 6; $i >= 0; $i--) { $pts[] = round($base * (1 - ($i / 6) * $swing + (($i % 2) ? -0.04 : 0.03)), 1); }
            $pts[6] = $end;
            return $pts;
        };

        // ── Base HR data — reuse host component vars when present, else compute (self-contained) ──
        if (! isset($totalActive)) { $totalActive = \App\Models\Employee::where('status', 'active')->count(); }
        if (! isset($onboarding)) { $onboarding = \App\Models\Employee::where('status', 'onboarding')->count(); }
        if (! isset($probation)) { $probation = \App\Models\Employee::where('status', 'probation')->count(); }
        if (! isset($newEmployeesCount)) { $newEmployeesCount = \App\Models\Employee::whereMonth('joining_date', $today->month)->whereYear('joining_date', $today->year)->count(); }
        if (! isset($presentToday)) { $presentToday = \App\Models\Attendance::where('date', $today->toDateString())->whereNotNull('check_in')->count(); }
        if (! isset($attendancePercent)) { $attendancePercent = $totalActive > 0 ? (int) round($presentToday / $totalActive * 100) : 0; }
        if (! isset($onLeaveTodayCount)) { $onLeaveTodayCount = \App\Models\LeaveRequest::where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count(); }
        if (! isset($pendingLeavesCount)) { $pendingLeavesCount = \App\Models\LeaveRequest::whereIn('status', ['pending', 'pending_hr'])->count(); }
        if (! isset($pendingOtCount)) { $pendingOtCount = \App\Models\OtRequest::where('status', 'pending')->count(); }
        if (! isset($pendingRegularisations)) { $pendingRegularisations = \App\Models\AttendanceRegularisation::where('status', 'pending')->count(); }
        if (! isset($pendingEncashments)) { $pendingEncashments = \App\Models\LeaveEncashment::where('status', 'pending')->count(); }
        if (! isset($activeWarnings)) { $activeWarnings = \App\Models\WarningLetter::whereIn('status', ['issued', 'acknowledged', 'under_review'])->count(); }
        if (! isset($onPipCount)) { $onPipCount = \App\Models\PipRecord::whereIn('status', ['active', 'under_review', 'extended'])->count(); }
        if (! isset($expiringDocuments)) { $expiringDocuments = \App\Models\Document::whereNotNull('expires_at')->whereBetween('expires_at', [$today, $today->copy()->addDays(30)])->get(); }
        if (! isset($upcomingProbations)) { $upcomingProbations = \App\Models\Employee::where('status', 'probation')->whereNotNull('probation_end_date')->where('probation_end_date', '<=', $today->copy()->addDays(30))->get(); }
        if (! isset($attendanceTrend)) {
            $attendanceTrend = collect();
            for ($i = 5; $i >= 0; $i--) {
                $mm = now()->subMonths($i);
                $present = \App\Models\Attendance::whereYear('date', $mm->year)->whereMonth('date', $mm->month)->whereNotNull('check_in')->count();
                $wd = max((int) $mm->copy()->startOfMonth()->diffInDaysFiltered(fn ($d) => ! $d->isSunday(), $mm->copy()->endOfMonth()), 1);
                $attendanceTrend->push(['month' => $mm->format('M'), 'rate' => $totalActive > 0 ? min(100, (int) round($present / ($totalActive * $wd) * 100)) : 0]);
            }
        }
        if (! isset($workforceComposition)) {
            $workforceComposition = \App\Models\Department::withCount(['employees' => fn ($q) => $q->where('status', 'active')])->get()
                ->map(fn ($d) => ['name' => $d->name, 'count' => $d->employees_count])->filter(fn ($d) => $d['count'] > 0)->values();
        }
        if (! isset($recentAuditLogs)) {
            $recentAuditLogs = \App\Models\AuditLog::with('user')->latest('id')->take(10)->get()->map(function ($log) {
                $model = strtolower(\Illuminate\Support\Str::afterLast((string) $log->auditable_type, '\\'));
                $log->display_action = match ($log->action) { 'created' => "created new {$model}", 'updated' => "updated {$model} details", 'deleted' => "removed a {$model}", default => (string) $log->action };
                return $log;
            });
        }
        if (! isset($upcomingBirthdays)) {
            $upcomingBirthdays = \App\Models\Employee::with(['user', 'department'])->where('status', 'active')->whereNotNull('date_of_birth')->get()
                ->map(function ($e) {
                    $dob = \Carbon\Carbon::parse($e->date_of_birth);
                    $next = now()->copy()->setDate(now()->year, $dob->month, min($dob->day, 28));
                    if ($next->lt(now()->startOfDay())) { $next->addYear(); }
                    return ['name' => $e->user?->name ?? '—', 'dept' => $e->department?->name ?? '', 'days' => (int) now()->startOfDay()->diffInDays($next), 'is_today' => $next->isToday()];
                })->filter(fn ($b) => $b['days'] <= 30)->sortBy('days')->take(6)->values();
        }
        if (! isset($pendingLeaveRequests)) {
            $pendingLeaveRequests = \App\Models\LeaveRequest::with(['employee.user', 'leaveType'])->whereIn('status', ['pending', 'pending_hr'])->latest()->take(3)->get();
        }
        if (! isset($pendingApprovals)) {
            $pendingApprovals = collect([
                ['label' => 'Leave', 'count' => $pendingLeavesCount, 'href' => $r('time-off.employees')],
                ['label' => 'Overtime', 'count' => $pendingOtCount, 'href' => $r('overtime.manage')],
                ['label' => 'Regularisations', 'count' => $pendingRegularisations, 'href' => $r('attendance.employees')],
                ['label' => 'Encashments', 'count' => $pendingEncashments, 'href' => $r('time-off.employees')],
            ]);
        }

        // ── HR-operations metrics ──
        $lateToday = \App\Models\Attendance::where('date', $today->toDateString())->where('is_late', true)->count();
        $wfhToday = \App\Models\WfhRequest::where('status', 'approved')->whereDate('start_date', '<=', $today)->whereDate('end_date', '>=', $today)->count();
        $officeToday = max($presentToday - $wfhToday, 0);
        $absentToday = max($totalActive - $presentToday - $onLeaveTodayCount, 0);
        $docVerifyPending = \App\Models\Document::whereNull('parent_id')->where('requires_acknowledgement', true)->whereDoesntHave('acknowledgements')->count();
        $onboardingOpen = \App\Models\OnboardingTask::where('phase', 'onboarding')->where('is_completed', false)->count();
        $offboardingOpen = \App\Models\OnboardingTask::where('phase', 'offboarding')->where('is_completed', false)->count();
        $leaveApprovedMonth = \App\Models\LeaveRequest::where('status', 'approved')->whereMonth('updated_at', $today->month)->whereYear('updated_at', $today->year)->count();
        $leaveRejectedMonth = \App\Models\LeaveRequest::where('status', 'rejected')->whereMonth('updated_at', $today->month)->whereYear('updated_at', $today->year)->count();
        $totalPending = $pendingLeavesCount + $pendingOtCount + $pendingRegularisations + $pendingEncashments;
        $confirmationDue = $upcomingProbations->count();
        $satisfaction = (int) max(45, min(98, round(82 + ($attendancePercent - 85) * 0.25 - $activeWarnings * 2 - $onPipCount * 2)));
        $attendanceHealth = $totalActive > 0 ? (int) round(($presentToday + $onLeaveTodayCount) / $totalActive * 100) : 0;

        $profileEmps = \App\Models\Employee::whereIn('status', ['active', 'probation'])->get(['date_of_birth', 'gender', 'joining_date', 'employment_type', 'job_title_id']);
        $profileCompletion = $profileEmps->count() > 0
            ? (int) round($profileEmps->avg(fn ($e) => (($e->date_of_birth ? 1 : 0) + ($e->gender ? 1 : 0) + ($e->joining_date ? 1 : 0) + ($e->employment_type ? 1 : 0) + ($e->job_title_id ? 1 : 0)) / 5 * 100))
            : 0;

        $anniversaries = \App\Models\Employee::with('user')->where('status', 'active')->whereNotNull('joining_date')->get()
            ->map(function ($e) {
                $j = \Carbon\Carbon::parse($e->joining_date);
                $next = now()->copy()->setDate(now()->year, $j->month, min($j->day, 28));
                if ($next->lt(now()->startOfDay())) { $next->addYear(); }
                return ['name' => $e->user?->name ?? '—', 'years' => $next->year - $j->year, 'days' => (int) now()->startOfDay()->diffInDays($next), 'when' => $j->format('d M')];
            })
            ->filter(fn ($a) => $a['days'] <= 30 && $a['years'] >= 1)->sortBy('days')->take(4)->values();

        // Hiring trend (joiners last 6 months)
        $hireCats = []; $hireData = [];
        for ($i = 5; $i >= 0; $i--) { $m = now()->subMonths($i); $hireCats[] = $m->format('M'); $hireData[] = \App\Models\Employee::whereYear('joining_date', $m->year)->whereMonth('joining_date', $m->month)->count(); }

        // Department attendance (this month)
        $monthStart = $today->copy()->startOfMonth();
        $workdaysMtd = max((int) $monthStart->copy()->diffInDaysFiltered(fn ($d) => ! $d->isSunday(), $today->copy()), 1);
        $presentByDept = \App\Models\Attendance::whereBetween('attendances.date', [$monthStart->toDateString(), $today->toDateString()])->whereNotNull('check_in')
            ->join('employees', 'employees.id', '=', 'attendances.employee_id')->selectRaw('employees.department_id as did, COUNT(*) as c')->groupBy('employees.department_id')->pluck('c', 'did');
        $deptAttendance = \App\Models\Department::withCount(['employees as hc' => fn ($q) => $q->where('status', 'active')])->get()
            ->filter(fn ($d) => $d->hc > 0)
            ->map(fn ($d) => ['name' => $d->name, 'rate' => min(100, (int) round(((int) ($presentByDept[$d->id] ?? 0)) / max($d->hc * $workdaysMtd, 1) * 100))])
            ->sortByDesc('rate')->take(6)->values();

        // ── KPI cards (8) ──
        $hrKpis = [
            ['label' => 'New Joiners', 'value' => $newEmployeesCount, 'icon' => 'user-plus', 'accent' => 'green', 'delta' => 0, 'dir' => 'flat', 'compare' => 'this month', 'spark' => $spark(max($newEmployeesCount, 1))],
            ['label' => 'Present Today', 'value' => $presentToday, 'icon' => 'check-circle', 'accent' => 'green', 'delta' => 0, 'dir' => 'flat', 'compare' => $attendancePercent.'% of '.$totalActive.' active', 'spark' => $spark(max($presentToday, 1))],
            ['label' => 'On Leave', 'value' => $onLeaveTodayCount, 'icon' => 'calendar-days', 'accent' => 'blue', 'delta' => 0, 'dir' => 'flat', 'compare' => 'out today', 'spark' => $spark(max($onLeaveTodayCount, 1))],
            ['label' => 'Regularization', 'value' => $pendingRegularisations, 'icon' => 'pencil-square', 'accent' => 'amber', 'delta' => 0, 'dir' => 'flat', 'compare' => 'pending review', 'spark' => $spark(max($pendingRegularisations, 1))],
            ['label' => 'Probation', 'value' => $probation, 'icon' => 'academic-cap', 'accent' => 'orange', 'delta' => 0, 'dir' => 'flat', 'compare' => $confirmationDue.' due soon', 'spark' => $spark(max($probation, 1))],
            ['label' => 'Doc Verification', 'value' => $docVerifyPending, 'icon' => 'document-check', 'accent' => 'red', 'delta' => 0, 'dir' => 'flat', 'compare' => 'awaiting ack', 'spark' => $spark(max($docVerifyPending, 1))],
            ['label' => 'Open HR Requests', 'value' => $totalPending, 'icon' => 'inbox-stack', 'accent' => 'red', 'delta' => 0, 'dir' => 'flat', 'compare' => 'across modules', 'spark' => $spark(max($totalPending, 1))],
            ['label' => 'Satisfaction', 'value' => $satisfaction.'%', 'icon' => 'face-smile', 'accent' => 'violet', 'delta' => 0, 'dir' => 'flat', 'compare' => 'engagement index', 'spark' => $spark($satisfaction, 0.08)],
        ];

        // ── Chart configs ──
        $axisStyle = ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px', 'fontFamily' => 'Inter']], 'axisBorder' => ['show' => false], 'axisTicks' => ['show' => false]];
        $gridStyle = ['borderColor' => '#F3E8DD', 'strokeDashArray' => 5, 'padding' => ['left' => 6, 'right' => 6]];

        $attChart = [
            'chart' => ['type' => 'area', 'height' => 300, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#F97316'], 'dataLabels' => ['enabled' => false], 'stroke' => ['curve' => 'smooth', 'width' => 3],
            'fill' => ['type' => 'gradient', 'gradient' => ['shadeIntensity' => 1, 'opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 90, 100]]],
            'grid' => $gridStyle,
            'xaxis' => array_merge(['categories' => $attendanceTrend->pluck('month')->all()], $axisStyle),
            'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px']]],
            'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Attendance %', 'data' => $attendanceTrend->pluck('rate')->map(fn ($v) => (float) $v)->all()]],
        ];
        $gauge = [
            'chart' => ['type' => 'radialBar', 'height' => 280, 'fontFamily' => 'Inter'], 'colors' => ['#F97316'],
            'plotOptions' => ['radialBar' => ['hollow' => ['size' => '64%'], 'track' => ['background' => '#F3E8DD'], 'dataLabels' => ['name' => ['show' => true, 'color' => '#6B7280', 'fontSize' => '13px', 'offsetY' => 22], 'value' => ['show' => true, 'color' => '#F97316', 'fontSize' => '34px', 'fontWeight' => 800, 'offsetY' => -8]]]],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'horizontal', 'gradientToColors' => ['#FB923C'], 'stops' => [0, 100]]],
            'stroke' => ['lineCap' => 'round'], 'labels' => ['Attendance Health'], 'series' => [$attendanceHealth],
        ];
        $deptDist = $workforceComposition->sortByDesc('count')->take(7)->values();
        $donut = [
            'chart' => ['type' => 'donut', 'height' => 270, 'fontFamily' => 'Inter'],
            'labels' => $deptDist->pluck('name')->all(), 'series' => $deptDist->pluck('count')->map(fn ($c) => (int) $c)->all(),
            'colors' => ['#F97316', '#FB923C', '#FDBA74', '#3B82F6', '#22C55E', '#8B5CF6', '#F59E0B'], 'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'Inter', 'labels' => ['colors' => '#6B7280'], 'markers' => ['radius' => 12]], 'stroke' => ['width' => 0],
            'plotOptions' => ['pie' => ['donut' => ['size' => '72%', 'labels' => ['show' => true, 'total' => ['show' => true, 'label' => 'Total', 'color' => '#6B7280']]]]],
        ];
        $leaveDonut = [
            'chart' => ['type' => 'donut', 'height' => 230, 'fontFamily' => 'Inter'],
            'labels' => ['Pending', 'Approved', 'Rejected'], 'series' => [$pendingLeavesCount, $leaveApprovedMonth, $leaveRejectedMonth],
            'colors' => ['#F59E0B', '#22C55E', '#EF4444'], 'dataLabels' => ['enabled' => false],
            'legend' => ['position' => 'bottom', 'fontFamily' => 'Inter', 'labels' => ['colors' => '#6B7280'], 'markers' => ['radius' => 12]], 'stroke' => ['width' => 0],
            'plotOptions' => ['pie' => ['donut' => ['size' => '70%']]],
        ];
        $deptBar = [
            'chart' => ['type' => 'bar', 'height' => 260, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#F97316'], 'plotOptions' => ['bar' => ['borderRadius' => 8, 'columnWidth' => '45%', 'horizontal' => false]], 'dataLabels' => ['enabled' => false],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'vertical', 'gradientToColors' => ['#FDBA74'], 'opacityFrom' => 1, 'opacityTo' => 0.85]],
            'grid' => $gridStyle, 'xaxis' => array_merge(['categories' => $deptAttendance->pluck('name')->all()], $axisStyle),
            'yaxis' => ['max' => 100, 'labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '12px']]], 'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Attendance %', 'data' => $deptAttendance->pluck('rate')->all()]],
        ];
        $hireCol = [
            'chart' => ['type' => 'bar', 'height' => 230, 'toolbar' => ['show' => false], 'fontFamily' => 'Inter', 'animations' => ['enabled' => true, 'speed' => 800]],
            'colors' => ['#22C55E'], 'plotOptions' => ['bar' => ['borderRadius' => 6, 'columnWidth' => '55%']], 'dataLabels' => ['enabled' => false],
            'fill' => ['type' => 'gradient', 'gradient' => ['shade' => 'light', 'type' => 'vertical', 'opacityFrom' => 1, 'opacityTo' => 0.8]],
            'grid' => $gridStyle, 'xaxis' => array_merge(['categories' => $hireCats], $axisStyle),
            'yaxis' => ['labels' => ['style' => ['colors' => '#9CA3AF', 'fontSize' => '11px']]], 'tooltip' => ['theme' => 'light'],
            'series' => [['name' => 'Joiners', 'data' => $hireData]],
        ];

        $hexBy = ['orange' => '#F97316', 'blue' => '#3B82F6', 'amber' => '#F59E0B', 'green' => '#22C55E', 'red' => '#EF4444', 'violet' => '#8B5CF6'];
        $bottomMetrics = [
            ['label' => 'Attendance %', 'value' => $attendancePercent.'%', 'accent' => 'orange', 'series' => $spark(max($attendancePercent, 1), 0.1)],
            ['label' => 'On Leave', 'value' => $onLeaveTodayCount, 'accent' => 'blue', 'series' => $spark(max($onLeaveTodayCount, 1))],
            ['label' => 'Regularization', 'value' => $pendingRegularisations, 'accent' => 'amber', 'series' => $spark(max($pendingRegularisations, 1))],
            ['label' => 'Onboarding', 'value' => $onboardingOpen, 'accent' => 'green', 'series' => $spark(max($onboardingOpen, 1))],
            ['label' => 'Docs Pending', 'value' => $docVerifyPending, 'accent' => 'red', 'series' => $spark(max($docVerifyPending, 1))],
            ['label' => 'Headcount', 'value' => $totalActive, 'accent' => 'violet', 'series' => $spark(max($totalActive, 1), 0.2)],
        ];
    @endphp

    {{-- ══ HERO ══ --}}
    <div class="dash-rise relative overflow-hidden rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-gradient-to-r from-[#FFF8F1] via-white to-[#FFF2E8] dark:from-[#0F172A] dark:via-[#111827] dark:to-[#1E293B] p-6 shadow-sm md:p-7">
        <div class="pointer-events-none absolute -right-10 -top-16 size-64 rounded-full bg-orange-500/10 blur-3xl"></div>
        <div class="relative flex flex-col items-start justify-between gap-6 lg:flex-row lg:items-center">
            <div>
                <div class="mb-2 inline-flex items-center gap-2 rounded-full border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 px-3 py-1 text-[11px] font-semibold text-[#6B7280] dark:text-zinc-400">
                    <flux:icon.user-group class="size-3.5 text-orange-500" /> HR Operations · {{ now()->format('l, d M Y') }}
                </div>
                <h1 class="text-[28px] font-extrabold tracking-tight text-[#111827] dark:text-white">{{ $greeting }}, {{ $firstName }} 👋</h1>
                <p class="mt-1 text-sm text-[#6B7280] dark:text-zinc-400">{{ $newEmployeesCount }} new joiner(s) this month · {{ $totalPending }} HR tasks need your attention.</p>
                <div class="mt-4 flex flex-wrap items-center gap-2.5">
                    <a href="{{ $r('employees.create') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl bg-gradient-to-r from-orange-500 to-orange-400 px-4 py-2.5 text-sm font-semibold text-white shadow-md shadow-orange-500/25 transition hover:shadow-lg"><flux:icon.plus class="size-4" /> Add Employee</a>
                    <a href="{{ $r('time-off.employees') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-[#6B7280] dark:text-zinc-400 transition hover:bg-[#FFF2E8] hover:text-orange-500"><flux:icon.calendar-days class="size-4" /> Leave Requests</a>
                    <a href="{{ $r('attendance.employees') }}" wire:navigate class="inline-flex items-center gap-2 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-[#6B7280] dark:text-zinc-400 transition hover:bg-[#FFF2E8] hover:text-orange-500"><flux:icon.clock class="size-4" /> Attendance</a>
                </div>
            </div>
            <div class="flex items-center gap-3">
                @foreach([['Company Health', $satisfaction.'%', 'heart'], ['Present', $attendancePercent.'%', 'check-circle']] as [$l, $v, $i])
                    <div class="flex items-center gap-3 rounded-2xl border border-white/70 bg-white/70 dark:border-white/10 dark:bg-white/5 px-4 py-3 shadow-sm backdrop-blur">
                        <div class="flex size-10 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-400 text-white"><flux:icon :name="$i" class="size-5" /></div>
                        <div><div class="text-lg font-extrabold leading-none text-[#111827] dark:text-white" x-data="countUp(@js($v))" x-text="display">{{ $v }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">{{ $l }}</div></div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- ══ KPI GRID (8) ══ --}}
    <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
        @foreach($hrKpis as $i => $k)
            <x-dashboard.kpi-card class="dash-rise" style="animation-delay: {{ $i * 55 }}ms"
                :label="$k['label']" :value="$k['value']" :icon="$k['icon']" :accent="$k['accent']"
                :delta="$k['delta']" :dir="$k['dir']" :compare="$k['compare']" :spark="$k['spark']" />
        @endforeach
    </div>

    {{-- ══ FIRST ROW — Attendance Overview (8) + Compliance gauge (4) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-8" title="Attendance Overview" subtitle="Daily rate · last 6 months" icon="chart-bar">
            <x-slot:actions>
                <div class="flex flex-wrap items-center gap-3 text-[11px] font-semibold text-[#6B7280] dark:text-zinc-400">
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-green-500"></span>Present {{ $presentToday }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-amber-500"></span>Late {{ $lateToday }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-red-400"></span>Absent {{ $absentToday }}</span>
                    <span class="flex items-center gap-1.5"><span class="size-2 rounded-full bg-blue-500"></span>WFH {{ $wfhToday }}</span>
                </div>
            </x-slot:actions>
            <x-dashboard.chart :options="$attChart" />
            <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach([['Present %', $attendancePercent.'%', 'green'], ['Late', $lateToday, 'amber'], ['Office', $officeToday, 'orange'], ['Work From Home', $wfhToday, 'blue']] as [$l, $v, $t])
                    <div class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 text-center"><div class="text-xl font-extrabold {{ $toneText[$t] }}">{{ $v }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">{{ $l }}</div></div>
                @endforeach
            </div>
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card class="dash-rise flex flex-col lg:col-span-4" title="Attendance Compliance" subtitle="Coverage health" icon="shield-check">
            <x-dashboard.chart :options="$gauge" />
            <div class="mt-auto grid grid-cols-2 gap-3 pt-2">
                <div class="rounded-xl bg-green-50 p-3 text-center"><div class="text-base font-extrabold text-green-600">{{ $attendanceHealth >= 85 ? 'Healthy' : 'Watch' }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">Status</div></div>
                <div class="rounded-xl bg-amber-50 p-3 text-center"><div class="text-base font-extrabold text-amber-600">{{ $lateToday }}</div><div class="text-[11px] font-medium text-[#6B7280] dark:text-zinc-400">Late today</div></div>
            </div>
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ SECOND ROW — Leave Management ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-8" title="Leave Management" subtitle="This month overview" icon="calendar-days" accent="blue">
            <x-slot:actions><a href="{{ $r('time-off.employees') }}" wire:navigate class="text-xs font-bold text-orange-500 transition hover:text-orange-600">Manage →</a></x-slot:actions>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                <x-dashboard.hr.stat-tile label="Pending" :value="$pendingLeavesCount" icon="clock" accent="amber" sub="awaiting action" />
                <x-dashboard.hr.stat-tile label="Approved" :value="$leaveApprovedMonth" icon="check-circle" accent="green" sub="this month" />
                <x-dashboard.hr.stat-tile label="Rejected" :value="$leaveRejectedMonth" icon="x-circle" accent="red" sub="this month" />
                <x-dashboard.hr.stat-tile label="On Leave Today" :value="$onLeaveTodayCount" icon="calendar-days" accent="blue" sub="out today" />
            </div>
            <div class="mt-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-4">
                <div class="mb-2 text-[11px] font-bold uppercase tracking-wide text-[#9CA3AF] dark:text-zinc-500">Recent pending requests</div>
                <div class="space-y-2">
                    @forelse($pendingLeaveRequests->take(3) as $lr)
                        <div class="flex items-center justify-between rounded-lg bg-white p-2.5 ring-1 dark:bg-zinc-800 ring-[#F3E8DD] dark:ring-white/10">
                            <div class="flex items-center gap-2.5"><span class="flex size-7 items-center justify-center rounded-full bg-orange-50 text-[10px] font-bold text-orange-600">{{ \Illuminate\Support\Str::of($lr->employee?->user?->name ?? 'U')->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}</span>
                                <div><div class="text-xs font-semibold text-[#111827] dark:text-white">{{ $lr->employee?->user?->name ?? 'Unknown' }}</div><div class="text-[10px] text-[#6B7280] dark:text-zinc-400">{{ $lr->leaveType?->name ?? 'Leave' }} · {{ $lr->days }}d</div></div>
                            </div>
                            <a href="{{ $r('time-off.employees') }}" wire:navigate class="rounded-lg bg-green-500 px-2.5 py-1 text-[10px] font-bold text-white transition hover:bg-green-600">Review</a>
                        </div>
                    @empty
                        <p class="py-3 text-center text-xs text-[#9CA3AF] dark:text-zinc-500">No pending leave requests.</p>
                    @endforelse
                </div>
            </div>
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card class="dash-rise lg:col-span-4" title="Leave Distribution" subtitle="Status split" icon="chart-pie" accent="green">
            <x-dashboard.chart :options="$leaveDonut" />
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ THIRD ROW — Employee Lifecycle ══ --}}
    <x-dashboard.hr.section-card class="dash-rise" title="Employee Lifecycle" subtitle="Onboarding → confirmation → records" icon="users" accent="violet">
        <div class="grid grid-cols-2 gap-3 md:grid-cols-3 lg:grid-cols-6">
            <x-dashboard.hr.stat-tile label="Onboarding" :value="$onboardingOpen" icon="rocket-launch" accent="green" sub="open tasks" />
            <x-dashboard.hr.stat-tile label="Offboarding" :value="$offboardingOpen" icon="arrow-right-start-on-rectangle" accent="red" sub="clearances" />
            <x-dashboard.hr.stat-tile label="Probation" :value="$probation" icon="academic-cap" accent="orange" sub="under review" />
            <x-dashboard.hr.stat-tile label="Confirmation Due" :value="$confirmationDue" icon="check-badge" accent="amber" sub="next 30 days" />
            <x-dashboard.hr.stat-tile label="Doc Verification" :value="$docVerifyPending" icon="document-check" accent="red" sub="pending ack" />
            <x-dashboard.hr.stat-tile label="Profile Completion" :value="$profileCompletion.'%'" icon="identification" accent="blue" :progress="$profileCompletion" />
        </div>
    </x-dashboard.hr.section-card>

    {{-- ══ FOURTH ROW — Hiring & Department ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-5" title="Hiring & Onboarding" subtitle="Joiners · last 6 months" icon="user-plus" accent="green">
            <x-dashboard.chart :options="$hireCol" />
            <div class="mt-3 grid grid-cols-3 gap-3">
                <div class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 text-center"><div class="text-lg font-extrabold text-green-600">{{ $newEmployeesCount }}</div><div class="text-[10px] font-medium text-[#6B7280] dark:text-zinc-400">Joining This Month</div></div>
                <div class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 text-center"><div class="text-lg font-extrabold text-orange-600">{{ $onboarding }}</div><div class="text-[10px] font-medium text-[#6B7280] dark:text-zinc-400">In Onboarding</div></div>
                <div class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 text-center"><div class="text-lg font-extrabold text-amber-600">{{ $onboardingOpen }}</div><div class="text-[10px] font-medium text-[#6B7280] dark:text-zinc-400">Open Tasks</div></div>
            </div>
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card class="dash-rise lg:col-span-7" title="Department Overview" subtitle="Headcount &amp; attendance" icon="building-office-2" accent="blue">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>@if($deptDist->isNotEmpty())<x-dashboard.chart :options="$donut" />@else<p class="py-12 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No data.</p>@endif</div>
                <div>@if($deptAttendance->isNotEmpty())<x-dashboard.chart :options="$deptBar" />@else<p class="py-12 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No data.</p>@endif</div>
            </div>
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ FIFTH ROW — Pending Approvals + Quick Actions ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-7" title="Pending Approvals" subtitle="Across HR modules" icon="inbox-stack" accent="amber">
            <x-slot:actions><span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-bold text-orange-500">{{ $totalPending }} total</span></x-slot:actions>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                @foreach($pendingApprovals as $pa)
                    <a href="{{ $pa['href'] }}" wire:navigate class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-4 transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md">
                        <div class="text-2xl font-extrabold {{ $pa['count'] > 0 ? 'text-orange-500' : 'text-[#D8CCBE] dark:text-zinc-600' }}">{{ $pa['count'] }}</div>
                        <div class="mt-1 text-xs font-semibold text-[#6B7280] dark:text-zinc-400">{{ $pa['label'] }}</div>
                    </a>
                @endforeach
            </div>
        </x-dashboard.hr.section-card>

        <x-dashboard.hr.section-card class="dash-rise lg:col-span-5" title="Quick Actions" subtitle="Common HR tasks" icon="bolt">
            <div class="grid grid-cols-2 gap-3">
                @foreach([
                    ['Add Employee', 'user-plus', 'employees.create', 'green'],
                    ['Upload Documents', 'document-arrow-up', 'documents.index', 'blue'],
                    ['Assign Manager', 'user-group', 'employees.index', 'orange'],
                    ['Generate ID', 'identification', 'employees.create', 'violet'],
                    ['Attendance Report', 'clock', 'attendance.employees', 'amber'],
                    ['Leave Report', 'calendar-days', 'time-off.employees', 'blue'],
                ] as [$l, $i, $route, $t])
                    <a href="{{ $r($route) }}" wire:navigate class="group flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3 transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md">
                        <div class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$t] }} transition group-hover:scale-110"><flux:icon :name="$i" class="size-[18px]" /></div>
                        <span class="text-xs font-semibold text-[#111827] dark:text-white">{{ $l }}</span>
                    </a>
                @endforeach
            </div>
        </x-dashboard.hr.section-card>
    </div>

    {{-- ══ BOTTOM — Recent Activity (7) + Compliance & Reminders (5) ══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-12">
        <x-dashboard.hr.section-card class="dash-rise lg:col-span-7" title="Recent Employee Activities" subtitle="Latest changes" icon="bell-alert">
            <x-slot:actions><a href="{{ $r('notifications.index') }}" wire:navigate class="text-xs font-bold text-orange-500 transition hover:text-orange-600">View all →</a></x-slot:actions>
            <div class="max-h-[320px] space-y-3 overflow-y-auto pr-1">
                @forelse($recentAuditLogs->take(8) as $log)
                    @php $dot = ['created' => 'bg-green-500', 'updated' => 'bg-blue-500', 'deleted' => 'bg-red-500'][$log->action] ?? 'bg-orange-500'; @endphp
                    <div class="flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-3">
                        <span class="size-2.5 shrink-0 rounded-full {{ $dot }}"></span>
                        <span class="flex size-8 shrink-0 items-center justify-center rounded-full bg-white text-[10px] font-bold text-orange-500 dark:bg-zinc-800 ring-1 ring-[#F3E8DD] dark:ring-white/10">{{ \Illuminate\Support\Str::of($log->user?->name ?? 'System')->explode(' ')->take(2)->map(fn ($p) => $p[0] ?? '')->implode('') }}</span>
                        <p class="min-w-0 flex-1 truncate text-sm text-[#6B7280] dark:text-zinc-400"><span class="font-semibold text-[#111827] dark:text-white">{{ $log->user?->name ?? 'System' }}</span> {{ $log->display_action }}</p>
                        <span class="shrink-0 text-[11px] font-medium text-[#9CA3AF] dark:text-zinc-500">{{ $log->created_at?->diffForHumans(null, true) }}</span>
                    </div>
                @empty
                    <p class="py-8 text-center text-sm text-[#9CA3AF] dark:text-zinc-500">No recent activity.</p>
                @endforelse
            </div>
        </x-dashboard.hr.section-card>

        <div class="dash-rise space-y-6 lg:col-span-5">
            <x-dashboard.hr.section-card title="Compliance" subtitle="Risks &amp; reviews" icon="shield-exclamation" accent="red">
                <div class="grid grid-cols-2 gap-3">
                    @foreach([
                        ['Missing / Expiring Docs', $expiringDocuments->count(), 'document-text', $expiringDocuments->count() > 0 ? 'red' : 'green'],
                        ['Policy Acknowledgement', $docVerifyPending, 'check-badge', $docVerifyPending > 0 ? 'amber' : 'green'],
                        ['Probation Reviews', $confirmationDue, 'academic-cap', $confirmationDue > 0 ? 'blue' : 'green'],
                        ['Active Warnings', $activeWarnings, 'exclamation-triangle', $activeWarnings > 0 ? 'amber' : 'green'],
                    ] as [$l, $v, $i, $t])
                        <div class="rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-4">
                            <div class="flex items-center justify-between"><span class="flex size-9 items-center justify-center rounded-lg {{ $toneSoft[$t] }}"><flux:icon :name="$i" class="size-[18px]" /></span><span class="text-2xl font-extrabold {{ $v > 0 ? $toneText[$t] : 'text-[#D8CCBE] dark:text-zinc-600' }}">{{ $v }}</span></div>
                            <div class="mt-2 text-xs font-semibold text-[#6B7280] dark:text-zinc-400">{{ $l }}</div>
                        </div>
                    @endforeach
                </div>
            </x-dashboard.hr.section-card>

            <x-dashboard.hr.section-card title="Celebrations" subtitle="Birthdays &amp; anniversaries" icon="cake" accent="violet">
                <div class="space-y-2.5">
                    @foreach($upcomingBirthdays->take(3) as $b)
                        <div class="flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-2.5">
                            <span class="flex size-8 items-center justify-center rounded-lg bg-orange-50 text-orange-500"><flux:icon.cake class="size-4" /></span>
                            <div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-[#111827] dark:text-white">{{ $b['name'] }}</div><div class="text-[10px] text-[#6B7280] dark:text-zinc-400">Birthday · {{ $b['dept'] ?: 'Team' }}</div></div>
                            <span class="rounded-lg bg-white px-2 py-1 text-[10px] font-bold dark:bg-zinc-800 text-orange-500 ring-1 ring-[#F3E8DD] dark:ring-white/10">{{ $b['is_today'] ? 'Today' : 'in '.$b['days'].'d' }}</span>
                        </div>
                    @endforeach
                    @forelse($anniversaries as $a)
                        <div class="flex items-center gap-3 rounded-xl border border-[#F3E8DD] dark:border-white/10 bg-[#FFFDF8] dark:bg-white/5 p-2.5">
                            <span class="flex size-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600"><flux:icon.trophy class="size-4" /></span>
                            <div class="min-w-0 flex-1"><div class="truncate text-sm font-semibold text-[#111827] dark:text-white">{{ $a['name'] }}</div><div class="text-[10px] text-[#6B7280] dark:text-zinc-400">{{ $a['years'] }}y work anniversary</div></div>
                            <span class="rounded-lg bg-white px-2 py-1 text-[10px] font-bold dark:bg-zinc-800 text-violet-600 ring-1 ring-[#F3E8DD] dark:ring-white/10">{{ $a['days'] === 0 ? 'Today' : 'in '.$a['days'].'d' }}</span>
                        </div>
                    @empty
                    @endforelse
                    @if($upcomingBirthdays->isEmpty() && $anniversaries->isEmpty())
                        <p class="py-3 text-center text-xs text-[#9CA3AF] dark:text-zinc-500">No upcoming celebrations.</p>
                    @endif
                </div>
            </x-dashboard.hr.section-card>
        </div>
    </div>

    {{-- ══ BOTTOM METRICS — 6 mini area charts ══ --}}
    <div class="grid grid-cols-2 gap-4 lg:grid-cols-6">
        @foreach($bottomMetrics as $m)
            @php
                $mini = [
                    'chart' => ['type' => 'area', 'height' => 54, 'sparkline' => ['enabled' => true], 'animations' => ['enabled' => true, 'speed' => 700]],
                    'colors' => [$hexBy[$m['accent']] ?? '#F97316'], 'stroke' => ['curve' => 'smooth', 'width' => 2],
                    'fill' => ['type' => 'gradient', 'gradient' => ['opacityFrom' => 0.35, 'opacityTo' => 0.0, 'stops' => [0, 100]]],
                    'tooltip' => ['enabled' => false], 'series' => [['name' => $m['label'], 'data' => array_map('floatval', $m['series'])]],
                ];
            @endphp
            <div class="dash-rise rounded-2xl border border-[#F3E8DD] dark:border-white/10 bg-white dark:bg-zinc-900 p-4 shadow-sm transition hover:-translate-y-1 hover:shadow-lg">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-[#9CA3AF] dark:text-zinc-500">{{ $m['label'] }}</div>
                <div class="mt-1 text-xl font-extrabold text-[#111827] dark:text-white" x-data="countUp(@js((string) $m['value']))" x-text="display">{{ $m['value'] }}</div>
                <x-dashboard.chart :options="$mini" class="mt-1" />
            </div>
        @endforeach
    </div>
</flux:main>
