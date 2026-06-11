@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">

    @php
        $user = auth()->user();
        $employee = $user->employee;
        $isEmp = $user->role?->value === 'employee';
        $isMgr = $user->isManager();
        $isHr = $user->isHrAdmin() || $user->isSuperAdmin();
        $isFin = $user->canApproveFinance();
        $isDir = $user->role?->value === 'director';
        $isSA = $user->isSuperAdmin();

        $company = \App\Models\Company::first()
            ?? new \App\Models\Company(['name' => 'Pulse HRMS', 'primary_color' => '#f97316']);

        $unread = $user->unreadNotifications()->count();
        $inboxRoute = Route::has('notifications.index') ? route('notifications.index') : '#';

        // Pure employee — no management abilities at all
        $pureEmployee = $isEmp && !$isMgr && !$isHr && !$isFin && !$isDir;

        $searchLinks = collect([
            ['label' => 'Dashboard', 'route' => route('dashboard'), 'caption' => 'Home overview'],
            ['label' => 'My Attendance', 'route' => route('attendance.my'), 'caption' => 'Daily check-in and attendance'],
            ['label' => 'My Leave', 'route' => route('time-off.my'), 'caption' => 'Leave balance and requests'],
            ['label' => 'My Overtime', 'route' => route('overtime.my'), 'caption' => 'Overtime requests'],
            ['label' => 'My Payslips', 'route' => route('payroll.payslips'), 'caption' => 'Payroll and salary slips'],
            ['label' => 'Expense Claims', 'route' => route('operations.expenses'), 'caption' => 'Operations and reimbursements'],
            ['label' => 'Documents', 'route' => route('documents.index'), 'caption' => 'HR and employee documents'],
            ['label' => 'Notifications', 'route' => $inboxRoute, 'caption' => 'Inbox and recent alerts'],
        ]);

        if (Route::has('employees.index')) {
            $searchLinks->push(['label' => 'Manage Employees', 'route' => route('employees.index'), 'caption' => 'Employee directory and records']);
        }
        if (Route::has('employees.directory')) {
            $searchLinks->push(['label' => 'Directory', 'route' => route('employees.directory'), 'caption' => 'Browse employee directory']);
        }
        if (Route::has('employees.org-chart')) {
            $searchLinks->push(['label' => 'Org Chart', 'route' => route('employees.org-chart'), 'caption' => 'Reporting structure']);
        }
        if ($user->canApproveLeave()) {
            $searchLinks->push(['label' => 'All Attendance', 'route' => route('attendance.employees'), 'caption' => 'Attendance review for all employees']);
            $searchLinks->push(['label' => 'All Leave', 'route' => route('time-off.employees'), 'caption' => 'Leave approvals and tracking']);
        }
        if ($isMgr || $user->canApproveLeave()) {
            $searchLinks->push(['label' => 'Team Attendance', 'route' => route('attendance.team'), 'caption' => 'Team attendance status']);
            $searchLinks->push(['label' => 'Team Leave', 'route' => route('time-off.team'), 'caption' => 'Team leave requests']);
        }
        if ($user->canApproveOt()) {
            $searchLinks->push(['label' => 'Manage OT Requests', 'route' => route('overtime.manage'), 'caption' => 'Approve or reject overtime']);
        }
        if ($user->canRunPayroll()) {
            $searchLinks->push(['label' => 'Payroll Overview', 'route' => route('payroll.overview'), 'caption' => 'Payroll summary and cycles']);
            $searchLinks->push(['label' => 'Run Payroll', 'route' => route('payroll.process'), 'caption' => 'Process payroll cycles']);
        }
        if (Route::has('settings.general') && $user->canManageSettings()) {
            $searchLinks->push(['label' => 'Settings', 'route' => route('settings.general'), 'caption' => 'General company settings']);
        }

        $searchLinks = $searchLinks->unique('route')->values();
    @endphp

    <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">

        {{-- Brand --}}
        <flux:sidebar.brand :name="$company->name" :href="route('dashboard')" wire:navigate>
            <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg"
                style="background-color: {{ $company->primary_color ?? '#f97316' }}">
                @if($company->logo)
                    <img src="{{ asset('storage/' . $company->logo) }}" class="size-6 object-contain"
                        alt="{{ $company->name }}">
                @else
                    <svg class="size-4 fill-white" viewBox="0 0 24 24">
                        <path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z" />
                    </svg>
                @endif
            </x-slot>
        </flux:sidebar.brand>

        <flux:sidebar.nav class="px-2">
            @auth

                {{-- ════════════════════════════════════════════
                EMPLOYEE SIDEBAR
                ════════════════════════════════════════════ --}}
                @if($pureEmployee)

                    @php
                        $pendingLeave = $employee?->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count() ?? 0;
                        $pendingOt = $employee?->otRequests()->pending()->count() ?? 0;
                        $activeWarnings = $employee?->activeWarnings()->count() ?? 0;
                        $hasActivePip = $employee?->activePip()->exists() ?? false;
                        $showNexflow = $employee && in_array($employee->ot_tracking_source, ['nexflow', 'hybrid'], true);

                        $pendingDocs = 0;
                        if ($employee) {
                            $pendingDocs = \App\Models\Document::whereNull('parent_id')
                                ->where('requires_acknowledgement', true)
                                ->where(function ($q) use ($employee) {
                                    $q->where('visibility', 'all')
                                        ->orWhere('category', 'policy')
                                        ->orWhere('employee_id', $employee->id);
                                })
                                ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('employee_id', $employee->id))
                                ->count();
                        }
                    @endphp

                    {{-- Dashboard --}}
                    <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')"
                        wire:navigate>
                        Dashboard
                    </flux:sidebar.item>

                    {{-- My Profile --}}
                    <flux:sidebar.item icon="user-circle" :href="route('profile.edit')"
                        :current="request()->routeIs('profile.edit')" wire:navigate>
                        My Profile
                    </flux:sidebar.item>

                    {{-- Attendance --}}
                    <flux:sidebar.item icon="clock" :href="route('attendance.my')" :current="request()->routeIs('attendance.my')"
                        wire:navigate>
                        Attendance
                    </flux:sidebar.item>

                    {{-- Leave --}}
                    <flux:sidebar.item icon="calendar-days" :href="route('time-off.my')"
                        :current="request()->routeIs('time-off.my')" wire:navigate>
                        <div class="flex items-center gap-2">
                            Leave
                            @if($pendingLeave > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $pendingLeave > 9 ? '9+' : $pendingLeave }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- Work From Home --}}
                    <flux:sidebar.item icon="home-modern" :href="route('wfh.my')" :current="request()->routeIs('wfh.my')"
                        wire:navigate>
                        Work From Home
                    </flux:sidebar.item>

                    {{-- Overtime --}}
                    <flux:sidebar.item icon="bolt" :href="route('overtime.my')" :current="request()->routeIs('overtime.my')"
                        wire:navigate>
                        <div class="flex items-center gap-2">
                            Overtime
                            @if($pendingOt > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $pendingOt > 9 ? '9+' : $pendingOt }}</span>
                            @endif
                            @if($showNexflow)
                                <span
                                    class="inline-flex items-center rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-300">Nexflow</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- Performance --}}
                    <flux:sidebar.group heading="Performance" icon="arrow-trending-up" :expandable="true"
                        :expanded="request()->routeIs('performance.dashboard', 'performance.my', 'performance.my-warnings', 'performance.pip.my', 'performance.promotions.my')">
                        <flux:sidebar.item :href="route('performance.dashboard')" :current="request()->routeIs('performance.dashboard')"
                            wire:navigate>
                            My Performance
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my')" :current="request()->routeIs('performance.my')"
                            wire:navigate>
                            My Review
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my-warnings')"
                            :current="request()->routeIs('performance.my-warnings')" wire:navigate>
                            <div class="flex items-center gap-2">
                                My Warnings
                                @if($activeWarnings > 0)
                                    <span
                                        class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $activeWarnings > 9 ? '9+' : $activeWarnings }}</span>
                                @endif
                            </div>
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.pip.my')"
                            :current="request()->routeIs('performance.pip.my')" wire:navigate>
                            <div class="flex items-center gap-2">
                                My Improvement Plan
                                @if($hasActivePip)
                                    <span
                                        class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">1</span>
                                @endif
                            </div>
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.promotions.my')"
                            :current="request()->routeIs('performance.promotions.my')" wire:navigate>
                            My Promotions
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Development --}}
                    <flux:sidebar.group heading="Development" icon="academic-cap" :expandable="true"
                        :expanded="request()->routeIs('performance.goals', 'performance.my-kpis')">
                        <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')"
                            wire:navigate>
                            My Goals
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my-kpis')"
                            :current="request()->routeIs('performance.my-kpis')" wire:navigate>
                            My KPIs
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Payroll — reimbursements not shown (requires run-payroll, 403 for employee) --}}
                    <flux:sidebar.group heading="Payroll" icon="banknotes" :expandable="true"
                        :expanded="request()->routeIs('payroll.payslips', 'operations.expenses')">
                        <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')"
                            wire:navigate>
                            My Payslips
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('operations.expenses')"
                            :current="request()->routeIs('operations.expenses')" wire:navigate>
                            Expense Claims
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Documents --}}
                    <flux:sidebar.item icon="document-text" :href="route('documents.index')"
                        :current="request()->routeIs('documents.*')" wire:navigate>
                        <div class="flex items-center gap-2">
                            Documents
                            @if($pendingDocs > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-amber-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $pendingDocs > 9 ? '9+' : $pendingDocs }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- Inbox --}}
                    <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')"
                        wire:navigate>
                        <div class="flex items-center gap-2">
                            Inbox
                            @if($unread > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- ════════════════════════════════════════════
                    MANAGER SIDEBAR
                    ════════════════════════════════════════════ --}}
                @elseif($isMgr && !$isHr && !$isFin && !$isDir)

                    <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard')"
                        wire:navigate>Dashboard</flux:sidebar.item>
                    <flux:sidebar.item icon="presentation-chart-line" :href="route('dashboard.manager')"
                        :current="request()->routeIs('dashboard.manager')" wire:navigate>Team View</flux:sidebar.item>

                    <flux:sidebar.group heading="My Work" icon="user-circle" :expandable="true"
                        :expanded="request()->routeIs('attendance.my', 'time-off.my', 'overtime.my', 'wfh.my', 'payroll.payslips', 'operations.expenses')">
                        <flux:sidebar.item :href="route('attendance.my')" :current="request()->routeIs('attendance.my')"
                            wire:navigate>My Attendance</flux:sidebar.item>
                        <flux:sidebar.item :href="route('time-off.my')" :current="request()->routeIs('time-off.my')"
                            wire:navigate>My Leave</flux:sidebar.item>
                        <flux:sidebar.item :href="route('overtime.my')" :current="request()->routeIs('overtime.my')"
                            wire:navigate>My Overtime</flux:sidebar.item>
                        <flux:sidebar.item :href="route('wfh.my')" :current="request()->routeIs('wfh.my')"
                            wire:navigate>My WFH Requests</flux:sidebar.item>
                        <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')"
                            wire:navigate>My Payslip</flux:sidebar.item>
                        <flux:sidebar.item :href="route('operations.expenses')"
                            :current="request()->routeIs('operations.expenses')" wire:navigate>Expense Claims
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="My Team" icon="users" :expandable="true"
                        :expanded="request()->routeIs('attendance.team', 'time-off.team', 'performance.team', 'overtime.manage', 'wfh.manage', 'performance.warnings.manage', 'performance.pip.manage')">
                        <flux:sidebar.item :href="route('attendance.team')" :current="request()->routeIs('attendance.team')"
                            wire:navigate>Team Attendance</flux:sidebar.item>
                        <flux:sidebar.item :href="route('time-off.team')" :current="request()->routeIs('time-off.team')"
                            wire:navigate>Team Leave</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.team')" :current="request()->routeIs('performance.team')"
                            wire:navigate>Team Reviews</flux:sidebar.item>
                        <flux:sidebar.item :href="route('overtime.manage')" :current="request()->routeIs('overtime.manage')"
                            wire:navigate>Overtime Requests</flux:sidebar.item>
                        @can('approve_wfh')
                            <flux:sidebar.item :href="route('wfh.manage')" :current="request()->routeIs('wfh.manage')"
                                wire:navigate>WFH Requests</flux:sidebar.item>
                        @endcan
                        @can('manage_warning_letters')
                            <flux:sidebar.item :href="route('performance.warnings.manage')"
                                :current="request()->routeIs('performance.warnings.manage')" wire:navigate>Warning Letters
                            </flux:sidebar.item>
                        @endcan
                        @can('manage_pip')
                            <flux:sidebar.item :href="route('performance.pip.manage')"
                                :current="request()->routeIs('performance.pip.manage')" wire:navigate>Improvement Plans
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="Performance" icon="arrow-trending-up" :expandable="true"
                        :expanded="request()->routeIs('performance.dashboard', 'performance.my', 'performance.goals', 'performance.my-kpis', 'performance.my-warnings', 'performance.warnings.manage', 'performance.pip.my', 'performance.pip.manage', 'performance.promotions.my', 'performance.promotions.manage')">
                        <flux:sidebar.item :href="route('performance.dashboard')" :current="request()->routeIs('performance.dashboard')"
                            wire:navigate>My Performance</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my')" :current="request()->routeIs('performance.my')"
                            wire:navigate>My Review</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')"
                            wire:navigate>My Goals</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my-kpis')"
                            :current="request()->routeIs('performance.my-kpis')" wire:navigate>My KPIs</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my-warnings')"
                            :current="request()->routeIs('performance.my-warnings')" wire:navigate>My Warnings
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.pip.my')"
                            :current="request()->routeIs('performance.pip.my')" wire:navigate>My Improvement Plan
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.promotions.my')"
                            :current="request()->routeIs('performance.promotions.my')" wire:navigate>My Promotions
                        </flux:sidebar.item>
                        @can('manage_promotions')
                            <flux:sidebar.item :href="route('performance.promotions.manage')"
                                :current="request()->routeIs('performance.promotions.manage')" wire:navigate>Manage Promotions
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    <flux:sidebar.item icon="document-text" :href="route('documents.index')"
                        :current="request()->routeIs('documents.*')" wire:navigate>Documents</flux:sidebar.item>

                    <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')">
                        <div class="flex items-center gap-2">
                            Inbox
                            @if($unread > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- ════════════════════════════════════════════
                    FINANCE SIDEBAR
                    ════════════════════════════════════════════ --}}
                @elseif($isFin && !$isHr)

                    <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard')"
                        wire:navigate>Dashboard</flux:sidebar.item>
                    <flux:sidebar.item icon="banknotes" :href="route('dashboard.finance')"
                        :current="request()->routeIs('dashboard.finance')" wire:navigate>Finance View</flux:sidebar.item>

                    <flux:sidebar.group heading="Payroll" icon="banknotes" :expandable="true"
                        :expanded="request()->routeIs('payroll.*')">
                        <flux:sidebar.item :href="route('payroll.finance-approve')"
                            :current="request()->routeIs('payroll.finance-approve')" wire:navigate>Finance Approval
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('payroll.incentives')"
                            :current="request()->routeIs('payroll.incentives')" wire:navigate>Incentives</flux:sidebar.item>
                        <flux:sidebar.item :href="route('payroll.reimbursements')"
                            :current="request()->routeIs('payroll.reimbursements')" wire:navigate>Reimbursements
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')"
                            wire:navigate>My Payslip</flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.group heading="My Work" icon="user-circle" :expandable="true"
                        :expanded="request()->routeIs('attendance.my', 'time-off.my', 'wfh.my', 'operations.expenses')">
                        <flux:sidebar.item :href="route('attendance.my')" :current="request()->routeIs('attendance.my')"
                            wire:navigate>My Attendance</flux:sidebar.item>
                        <flux:sidebar.item :href="route('time-off.my')" :current="request()->routeIs('time-off.my')"
                            wire:navigate>My Leave</flux:sidebar.item>
                        <flux:sidebar.item :href="route('wfh.my')" :current="request()->routeIs('wfh.my')"
                            wire:navigate>My WFH Requests</flux:sidebar.item>
                        <flux:sidebar.item :href="route('operations.expenses')"
                            :current="request()->routeIs('operations.expenses')" wire:navigate>Expense Claims
                        </flux:sidebar.item>
                    </flux:sidebar.group>

                    <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')">
                        <div class="flex items-center gap-2">
                            Inbox
                            @if($unread > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- ════════════════════════════════════════════
                    HR ADMIN / SUPER ADMIN / DIRECTOR SIDEBAR
                    ════════════════════════════════════════════ --}}
                @else

                    <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')"
                        :current="request()->routeIs('dashboard') && !request()->routeIs('dashboard.*')" wire:navigate>Dashboard
                    </flux:sidebar.item>
                    @if($isSA || $isDir)
                        <flux:sidebar.item icon="chart-bar-square" :href="route('dashboard.executive')"
                            :current="request()->routeIs('dashboard.executive')" wire:navigate>Executive View</flux:sidebar.item>
                    @endif
                    @if($isHr)
                        <flux:sidebar.item icon="user-group" :href="route('dashboard.hr-admin')"
                            :current="request()->routeIs('dashboard.hr-admin')" wire:navigate>HR Overview</flux:sidebar.item>
                    @endif
                    @if($user->isDepartmentHead())
                        <flux:sidebar.item icon="building-office" :href="route('dashboard.department')"
                            :current="request()->routeIs('dashboard.department')" wire:navigate>Department View</flux:sidebar.item>
                    @endif

                    {{-- People --}}
                    @if($isHr)
                        <flux:sidebar.group heading="People" icon="users" :expandable="true"
                            :expanded="request()->routeIs('employees.*', 'performance.warnings.manage', 'performance.pip.manage')">
                            @if(Route::has('employees.index'))
                                <flux:sidebar.item :href="route('employees.index')" :current="request()->routeIs('employees.index')"
                                    wire:navigate>Manage Employees</flux:sidebar.item>
                            @endif
                            @if(Route::has('employees.onboarding-manager'))
                                <flux:sidebar.item :href="route('employees.onboarding-manager')"
                                    :current="request()->routeIs('employees.onboarding-manager')" wire:navigate>Onboarding
                                </flux:sidebar.item>
                            @endif
                            @if(Route::has('employees.offboarding-manager'))
                                <flux:sidebar.item :href="route('employees.offboarding-manager')"
                                    :current="request()->routeIs('employees.offboarding-manager')" wire:navigate>Offboarding
                                </flux:sidebar.item>
                            @endif
                            @can('manage_warning_letters')
                                <flux:sidebar.item :href="route('performance.warnings.manage')"
                                    :current="request()->routeIs('performance.warnings.manage')" wire:navigate>Warning Letters
                                </flux:sidebar.item>
                            @endcan
                            @can('manage_pip')
                                <flux:sidebar.item :href="route('performance.pip.manage')"
                                    :current="request()->routeIs('performance.pip.manage')" wire:navigate>Improvement Plans
                                </flux:sidebar.item>
                            @endcan
                            @if(Route::has('employees.directory'))
                                <flux:sidebar.item :href="route('employees.directory')"
                                    :current="request()->routeIs('employees.directory')" wire:navigate>Directory</flux:sidebar.item>
                            @endif
                            @if(Route::has('employees.org-chart'))
                                <flux:sidebar.item :href="route('employees.org-chart')"
                                    :current="request()->routeIs('employees.org-chart')" wire:navigate>Org Chart</flux:sidebar.item>
                            @endif
                        </flux:sidebar.group>
                    @elseif($isDir)
                        <flux:sidebar.group heading="Company" icon="building-office" :expandable="true"
                            :expanded="request()->routeIs('employees.directory', 'employees.org-chart')">
                            <flux:sidebar.item :href="route('employees.directory')"
                                :current="request()->routeIs('employees.directory')" wire:navigate>Directory</flux:sidebar.item>
                            <flux:sidebar.item :href="route('employees.org-chart')"
                                :current="request()->routeIs('employees.org-chart')" wire:navigate>Org Chart</flux:sidebar.item>
                        </flux:sidebar.group>
                    @endif

                    {{-- Attendance --}}
                    <flux:sidebar.group heading="Attendance" icon="clock" :expandable="true"
                        :expanded="request()->routeIs('attendance.my', 'attendance.team', 'attendance.employees', 'attendance.settings')">
                        <flux:sidebar.item :href="route('attendance.my')" :current="request()->routeIs('attendance.my')"
                            wire:navigate>My Attendance</flux:sidebar.item>
                        @if($isMgr || $user->hasPermission('approve_leave'))
                            <flux:sidebar.item :href="route('attendance.team')" :current="request()->routeIs('attendance.team')"
                                wire:navigate>Team Attendance</flux:sidebar.item>
                        @endif
                        @can('approve_leave')
                            <flux:sidebar.item :href="route('attendance.employees')"
                                :current="request()->routeIs('attendance.employees')" wire:navigate>All Attendance
                            </flux:sidebar.item>
                        @endcan
                        @can('manage_settings')
                            <flux:sidebar.item :href="route('attendance.settings')"
                                :current="request()->routeIs('attendance.settings')" wire:navigate>Attendance Settings
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    {{-- Biometric --}}
                    @can('manage_biometric')
                        <flux:sidebar.group heading="Biometric" icon="finger-print" :expandable="true"
                            :expanded="request()->routeIs('attendance.biometric', 'attendance.biometric-live')">
                            <flux:sidebar.item :href="route('attendance.biometric-live')"
                                :current="request()->routeIs('attendance.biometric-live')" wire:navigate>Live Punches
                            </flux:sidebar.item>
                            <flux:sidebar.item :href="route('attendance.biometric')"
                                :current="request()->routeIs('attendance.biometric')" wire:navigate>Device Sync
                            </flux:sidebar.item>
                        </flux:sidebar.group>
                    @endcan

                    {{-- Leave Management --}}
                    <flux:sidebar.group heading="Leave Management" icon="calendar-days" :expandable="true"
                        :expanded="request()->routeIs('time-off.my', 'time-off.team', 'time-off.employees', 'time-off.encashments', 'time-off.settings')">
                        <flux:sidebar.item :href="route('time-off.my')" :current="request()->routeIs('time-off.my')"
                            wire:navigate>My Leave</flux:sidebar.item>
                        @if($isMgr || $user->hasPermission('approve_leave'))
                            <flux:sidebar.item :href="route('time-off.team')" :current="request()->routeIs('time-off.team')"
                                wire:navigate>Team Leave</flux:sidebar.item>
                        @endif
                        @can('approve_leave')
                            <flux:sidebar.item :href="route('time-off.employees')"
                                :current="request()->routeIs('time-off.employees')" wire:navigate>All Leave</flux:sidebar.item>
                        @endcan
                        @if($isFin || $isHr)
                            <flux:sidebar.item :href="route('time-off.encashments')"
                                :current="request()->routeIs('time-off.encashments')" wire:navigate>Encashment Requests
                            </flux:sidebar.item>
                        @endif
                        @canany(['manage_leave_types', 'manage_leave_policies'])
                            <flux:sidebar.item :href="route('time-off.settings')" :current="request()->routeIs('time-off.settings')"
                                wire:navigate>Leave Settings</flux:sidebar.item>
                        @endcanany
                    </flux:sidebar.group>

                    {{-- Overtime --}}
                    <flux:sidebar.group heading="Overtime" icon="bolt" :expandable="true"
                        :expanded="request()->routeIs('overtime.my', 'overtime.manage')">
                        <flux:sidebar.item :href="route('overtime.my')" :current="request()->routeIs('overtime.my')"
                            wire:navigate>My Overtime</flux:sidebar.item>
                        @can('approve_overtime')
                            <flux:sidebar.item :href="route('overtime.manage')" :current="request()->routeIs('overtime.manage')"
                                wire:navigate>Approve OT Requests</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    {{-- Work From Home --}}
                    <flux:sidebar.group heading="Work From Home" icon="home" :expandable="true"
                        :expanded="request()->routeIs('wfh.my', 'wfh.manage')">
                        <flux:sidebar.item :href="route('wfh.my')" :current="request()->routeIs('wfh.my')"
                            wire:navigate>My WFH Requests</flux:sidebar.item>
                        @can('approve_wfh')
                            <flux:sidebar.item :href="route('wfh.manage')" :current="request()->routeIs('wfh.manage')"
                                wire:navigate>Approve WFH Requests</flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    {{-- Payroll --}}
                    <flux:sidebar.group heading="Payroll" icon="banknotes" :expandable="true"
                        :expanded="request()->routeIs('payroll.*')">
                        <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')"
                            wire:navigate>My Payslip</flux:sidebar.item>
                        @can('run_payroll')
                            <flux:sidebar.item :href="route('payroll.overview')" :current="request()->routeIs('payroll.overview')"
                                wire:navigate>Overview</flux:sidebar.item>
                            <flux:sidebar.item :href="route('payroll.process')" :current="request()->routeIs('payroll.process')"
                                wire:navigate>Run Payroll</flux:sidebar.item>
                            <flux:sidebar.item :href="route('payroll.components')"
                                :current="request()->routeIs('payroll.components')" wire:navigate>Components</flux:sidebar.item>
                        @endcan
                        @if($isFin || $isHr)
                            <flux:sidebar.item :href="route('payroll.incentives')"
                                :current="request()->routeIs('payroll.incentives')" wire:navigate>Incentives</flux:sidebar.item>
                            <flux:sidebar.item :href="route('payroll.reimbursements')"
                                :current="request()->routeIs('payroll.reimbursements')" wire:navigate>Reimbursements
                            </flux:sidebar.item>
                        @endif
                        @if($isFin)
                            <flux:sidebar.item :href="route('payroll.finance-approve')"
                                :current="request()->routeIs('payroll.finance-approve')" wire:navigate>Finance Approval
                            </flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- Performance --}}
                    <flux:sidebar.group heading="Performance" icon="arrow-trending-up" :expandable="true"
                        :expanded="request()->routeIs('performance.*')">
                        <flux:sidebar.item :href="route('performance.dashboard')" :current="request()->routeIs('performance.dashboard')"
                            wire:navigate>My Performance</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my')" :current="request()->routeIs('performance.my')"
                            wire:navigate>My Review</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')"
                            wire:navigate>My Goals</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my-kpis')"
                            :current="request()->routeIs('performance.my-kpis')" wire:navigate>My KPIs</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.my-warnings')"
                            :current="request()->routeIs('performance.my-warnings')" wire:navigate>My Warnings
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.pip.my')"
                            :current="request()->routeIs('performance.pip.my')" wire:navigate>My Improvement Plan
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.promotions.my')"
                            :current="request()->routeIs('performance.promotions.my')" wire:navigate>My Promotions
                        </flux:sidebar.item>
                        @if($isMgr || $user->hasPermission('approve_leave'))
                            <flux:sidebar.item :href="route('performance.team')" :current="request()->routeIs('performance.team')"
                                wire:navigate>Team Reviews</flux:sidebar.item>
                        @endif
                        @can('manage_employees')
                            <flux:sidebar.item :href="route('performance.employees')"
                                :current="request()->routeIs('performance.employees')" wire:navigate>All Reviews</flux:sidebar.item>
                        @endcan
                        @can('manage_review_cycles')
                            <flux:sidebar.item :href="route('performance.cycles')"
                                :current="request()->routeIs('performance.cycles')" wire:navigate>Review Cycles</flux:sidebar.item>
                        @endcan
                        @can('manage_scorecards')
                            <flux:sidebar.item :href="route('performance.kpi-dashboard')"
                                :current="request()->routeIs('performance.kpi-dashboard')" wire:navigate>KPI Dashboard
                            </flux:sidebar.item>
                        @endcan
                        @can('manage_kpi_templates')
                            <flux:sidebar.item :href="route('performance.kpi-templates')"
                                :current="request()->routeIs('performance.kpi-templates')" wire:navigate>KPI Templates
                            </flux:sidebar.item>
                        @endcan
                        @can('manage_promotions')
                            <flux:sidebar.item :href="route('performance.promotions.manage')"
                                :current="request()->routeIs('performance.promotions.manage')" wire:navigate>Manage Promotions
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>

                    {{-- Operations --}}
                    <flux:sidebar.group heading="Operations" icon="building-office-2" :expandable="true"
                        :expanded="request()->routeIs('operations.*', 'documents.*')">
                        @can('manage_employees')
                            <flux:sidebar.item :href="route('operations.assets')" :current="request()->routeIs('operations.assets')"
                                wire:navigate>Assets</flux:sidebar.item>
                        @endcan
                        <flux:sidebar.item :href="route('operations.expenses')"
                            :current="request()->routeIs('operations.expenses')" wire:navigate>Expense Claims
                        </flux:sidebar.item>
                        <flux:sidebar.item :href="route('documents.index')" :current="request()->routeIs('documents.*')"
                            wire:navigate>Documents</flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Reports --}}
                    @canany(['manage_employees', 'approve_leave', 'approve_overtime', 'run_payroll'])
                        <flux:sidebar.group heading="Reports" icon="document-chart-bar" :expandable="true"
                            :expanded="false">
                            @can('run_payroll')
                                <flux:sidebar.item :href="route('reports.payroll-summary')">Payroll Summary</flux:sidebar.item>
                            @endcan
                            @can('approve_leave')
                                <flux:sidebar.item :href="route('reports.attendance-summary')">Attendance Summary</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.leave-utilization')">Leave Utilization</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.leave-encashment-report')">Leave Encashments</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.attendance-compliance')">Attendance Compliance</flux:sidebar.item>
                            @endcan
                            @can('approve_overtime')
                                <flux:sidebar.item :href="route('reports.ot-records')">Overtime Records</flux:sidebar.item>
                            @endcan
                            @can('manage_employees')
                                <flux:sidebar.item :href="route('reports.performance-summary')">Performance Summary</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.kpi-summary')">KPI Summary</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.department-performance')">Department Performance</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.promotion-pipeline')">Promotion Pipeline</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.warning-letter-report')">Warning Letters Report</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.pip-progress')">PIP Progress</flux:sidebar.item>
                                <flux:sidebar.item :href="route('reports.employee-lifecycle')">Employee Lifecycle</flux:sidebar.item>
                            @endcan
                        </flux:sidebar.group>
                    @endcanany

                    {{-- Inbox --}}
                    <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')">
                        <div class="flex items-center gap-2">
                            Inbox
                            @if($unread > 0)
                                <span
                                    class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                @endif
            @endauth
        </flux:sidebar.nav>

        <flux:spacer />

        @can('manage_settings')
            <flux:sidebar.nav class="px-2 pb-1">
                <flux:sidebar.group heading="Settings" icon="cog-6-tooth" :expandable="true"
                    :expanded="request()->routeIs('settings.*')">
                    <flux:sidebar.item :href="route('settings.general')" :current="request()->routeIs('settings.general')"
                        wire:navigate>General</flux:sidebar.item>
                    @can('manage_roles')
                        <flux:sidebar.item :href="route('settings.roles')" :current="request()->routeIs('settings.roles')"
                            wire:navigate>Roles & Permissions</flux:sidebar.item>
                    @endcan
                    <flux:sidebar.item :href="route('settings.employment-types')"
                        :current="request()->routeIs('settings.employment-types')" wire:navigate>Employment Types
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('settings.work-modes')"
                        :current="request()->routeIs('settings.work-modes')" wire:navigate>Work Modes</flux:sidebar.item>
                    <flux:sidebar.item :href="route('settings.salary-cycles')"
                        :current="request()->routeIs('settings.salary-cycles')" wire:navigate>Salary Cycles
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('settings.job-titles')"
                        :current="request()->routeIs('settings.job-titles')" wire:navigate>Job Titles</flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>
        @endcan

        {{-- User profile --}}
        <div class="mt-1 border-t border-zinc-100 px-2 pb-2 pt-2 dark:border-zinc-800">
            <flux:dropdown position="top" align="start" class="w-full">
                <flux:sidebar.profile :name="auth()->user()->name" :initials="auth()->user()->initials()"
                    icon:trailing="chevrons-up-down" />
                <flux:menu class="w-56">
                    <div class="flex items-center gap-3 px-2 py-2">
                        <flux:avatar :initials="auth()->user()->initials()" size="sm" class="bg-brand-600 text-white" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">
                                {{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                        </div>
                    </div>
                    <div class="px-3 pb-2">
                        <span
                            class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold
                                {{ $isHr ? 'bg-violet-100 text-orange-700' : ($isFin ? 'bg-emerald-100 text-emerald-700' : ($isMgr ? 'bg-blue-100 text-blue-700' : 'bg-zinc-100 text-zinc-600')) }}">
                            {{ ucwords(str_replace('_', ' ', $user->role?->value ?? 'Employee')) }}
                        </span>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>Profile</flux:menu.item>
                    @can('manage_settings')
                        <flux:menu.item :href="route('settings.general')" icon="cog-6-tooth" wire:navigate>Settings
                        </flux:menu.item>
                    @endcan
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
                            class="w-full text-red-600 dark:text-red-400">Log out</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </div>

    </flux:sidebar>

    {{-- TOP HEADER --}}
    <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />
        <div class="ms-2 hidden max-w-xs flex-1 items-center gap-2 lg:flex" x-data>
            <flux:input placeholder="{{ __('Search anything...') }}" icon="magnifying-glass" size="sm"
                class="w-full cursor-pointer" kbd="⌘ K" readonly @click="$flux.modal('global-search').show()" />
        </div>
        <flux:spacer />
        <div class="flex items-center gap-1">
            <livewire:notifications />
        </div>
        <flux:dropdown position="bottom" align="end">
            <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()"
                class="ms-1 cursor-pointer" />
            <flux:menu class="w-52">
                <div class="flex items-center gap-2 px-2 py-2">
                    <flux:avatar :initials="auth()->user()->initials()" size="sm" class="bg-brand-600 text-white" />
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                        <p class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                    </div>
                </div>
                <flux:menu.separator />
                <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>Profile</flux:menu.item>
                <flux:menu.separator />
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">Log
                        out</flux:menu.item>
                </form>
            </flux:menu>
        </flux:dropdown>
    </flux:header>

    {{ $slot }}

    <flux:modal name="global-search" class="w-full max-w-2xl">
        <div x-data="{ q: '' }" x-on:keydown.escape.window="$flux.modal('global-search').close()" class="space-y-5">
            <div>
                <flux:heading size="lg">Search Anything</flux:heading>
                <flux:subheading>Jump quickly to the most-used pages in Pulse.</flux:subheading>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-3">
                    <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                    <input x-model="q" type="text" placeholder="Search dashboard, attendance, leave, payroll..."
                        class="w-full border-0 bg-transparent p-0 text-sm text-zinc-900 outline-none placeholder:text-zinc-400 focus:ring-0 dark:text-white">
                </div>
            </div>

            <div class="max-h-[420px] space-y-2 overflow-y-auto">
                @foreach($searchLinks as $item)
                    <a href="{{ $item['route'] }}" wire:navigate
                        x-show="'{{ \Illuminate\Support\Str::lower($item['label'] . ' ' . $item['caption']) }}'.includes(q.toLowerCase())"
                        class="block rounded-2xl border border-zinc-200 bg-white px-4 py-3 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-zinc-700 dark:hover:bg-zinc-900">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $item['label'] }}</div>
                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['caption'] }}</div>
                            </div>
                            <flux:icon.arrow-up-right class="size-4 text-zinc-300 dark:text-zinc-600" />
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </flux:modal>

    <flux:toast />

    @fluxScripts
</body>

</html>