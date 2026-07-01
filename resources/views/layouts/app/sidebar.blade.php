@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-[#FAFAFA] antialiased dark:bg-[#0B1220]">

    @php
        $user = auth()->user();
        $employee = $user->employee;
        $isEmp = $user->role?->value === 'employee';
        $isMgr = $user->isManager();
        $isHr = $user->isHrAdmin() || $user->isSuperAdmin();
        $isFin = $user->canApproveFinance();
        $isDir = $user->role?->value === 'director';
        $isSA = $user->isSuperAdmin();

        // Premium primary accent — orange across all roles (#F97316).
        $roleColor = '#f97316';
        $roleLabel = $user->role?->label() ?? 'Member';

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

    <flux:sidebar sticky collapsible
        style="--role-accent: {{ $roleColor }}; --color-accent: {{ $roleColor }}; --color-accent-content: {{ $roleColor }}; --color-accent-foreground: #ffffff;"
        class="pulse-sidebar border-e border-[#F3E8DD] bg-[#FFF8F1]">

        {{-- Brand --}}
        <flux:sidebar.brand :name="$company->name" :href="route('dashboard')" wire:navigate>
            <x-slot name="logo" class="flex aspect-square size-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-400 shadow-lg shadow-orange-500/30">
                @if($company->logo)
                    <img src="{{ asset('storage/' . $company->logo) }}" class="size-6 object-contain"
                        alt="{{ $company->name }}">
                @else
                    <svg class="size-5 fill-white" viewBox="0 0 24 24">
                        <path d="M13 2L4.5 13.5H11l-1 8.5 8.5-11.5H12l1-8.5z" />
                    </svg>
                @endif
            </x-slot>
        </flux:sidebar.brand>

        {{-- Role chip — colored by the current user's role --}}
        <div class="px-3 pb-1 pt-0.5 overflow-hidden whitespace-nowrap">
            <span class="inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-[10px] font-bold uppercase tracking-wider"
                style="color: {{ $roleColor }}; background-color: color-mix(in srgb, {{ $roleColor }} 14%, transparent);">
                <span class="size-1.5 rounded-full" style="background-color: {{ $roleColor }}"></span>
                {{ $roleLabel }}
            </span>
        </div>

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

                    {{-- Employee menu — order/visibility/labels are admin-controllable
                         (Settings > Sidebar Menu). Fail-open: unknown items show by default. --}}
                    @php
                        $employeeMenu = app(\App\Services\EmployeeMenu::class)->visible();
                        $menuBadges = [
                            'leave' => $pendingLeave,
                            'overtime' => $pendingOt,
                            'documents' => $pendingDocs,
                            'inbox' => $unread,
                        ];
                    @endphp

                    @foreach($employeeMenu as $mi)
                        @switch($mi['key'])
                            @case('performance')
                                <flux:sidebar.group :heading="$mi['label']" icon="arrow-trending-up" :expandable="true"
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
                                @break

                            @case('development')
                                <flux:sidebar.group :heading="$mi['label']" icon="academic-cap" :expandable="true"
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
                                @break

                            @case('payroll')
                                {{-- reimbursements not shown (requires run-payroll, 403 for employee) --}}
                                <flux:sidebar.group :heading="$mi['label']" icon="banknotes" :expandable="true"
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
                                @break

                            @default
                                @php $miHref = \Illuminate\Support\Facades\Route::has($mi['route'] ?? '') ? route($mi['route']) : '#'; @endphp
                                <flux:sidebar.item :icon="$mi['icon']" :href="$miHref" :current="request()->routeIs($mi['active'])" wire:navigate>
                                    <div class="flex items-center gap-2">
                                        {{ $mi['label'] }}
                                        @if(($mi['badge'] ?? null) && ($menuBadges[$mi['badge']] ?? 0) > 0)
                                            <span
                                                class="inline-flex items-center justify-center rounded-full {{ $mi['badge'] === 'inbox' ? 'bg-red-500' : 'bg-amber-500' }} px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $menuBadges[$mi['badge']] > 9 ? '9+' : $menuBadges[$mi['badge']] }}</span>
                                        @endif
                                        @if($mi['key'] === 'overtime' && $showNexflow)
                                            <span
                                                class="inline-flex items-center rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-300">Nexflow</span>
                                        @endif
                                    </div>
                                </flux:sidebar.item>
                        @endswitch
                    @endforeach

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

                    {{-- WORKSPACE --}}
                    <flux:sidebar.group heading="Workspace" icon="squares-2x2" :expandable="true" :expanded="true">
                        <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')"
                            :current="request()->routeIs('dashboard') && !request()->routeIs('dashboard.*')" wire:navigate>Dashboard
                        </flux:sidebar.item>
                        @if($isSA)
                            <flux:sidebar.item icon="chart-bar-square" :href="route('dashboard.executive')"
                                :current="request()->routeIs('dashboard.executive')" wire:navigate>Executive View</flux:sidebar.item>
                        @endif
                        @if($isDir && Route::has('dashboard.director'))
                            <flux:sidebar.item icon="presentation-chart-line" :href="route('dashboard.director')"
                                :current="request()->routeIs('dashboard.director')" wire:navigate>Director Dashboard</flux:sidebar.item>
                        @endif
                        @if($isHr)
                            <flux:sidebar.item icon="user-group" :href="route('dashboard.hr-admin')"
                                :current="request()->routeIs('dashboard.hr-admin')" wire:navigate>HR Overview</flux:sidebar.item>
                        @endif
                        @if($user->isDepartmentHead())
                            <flux:sidebar.item icon="building-office" :href="route('dashboard.department')"
                                :current="request()->routeIs('dashboard.department')" wire:navigate>Department View</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- People --}}
                    @if($isHr)
                        <flux:sidebar.group heading="People" icon="users" :expandable="true"
                            :expanded="request()->routeIs('employees.*', 'performance.warnings.manage', 'performance.pip.manage')">
                            @if(Route::has('employees.index'))
                                <flux:sidebar.item :href="route('employees.index')" :current="request()->routeIs('employees.index')"
                                    wire:navigate>Manage Employees</flux:sidebar.item>
                            @endif
                            @if(Route::has('employees.import'))
                                <flux:sidebar.item :href="route('employees.import')" :current="request()->routeIs('employees.import')"
                                    wire:navigate>Import Employees</flux:sidebar.item>
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

                    {{-- TIME & ATTENDANCE --}}
                    <flux:sidebar.group heading="Time & Attendance" icon="clock" :expandable="true"
                        :expanded="request()->routeIs('attendance.*', 'time-off.*', 'overtime.*', 'wfh.*')">
                        <flux:sidebar.item :href="route('attendance.my')" :current="request()->routeIs('attendance.my')" wire:navigate>My Attendance</flux:sidebar.item>
                        @if($isMgr || $user->hasPermission('approve_leave'))
                            <flux:sidebar.item :href="route('attendance.team')" :current="request()->routeIs('attendance.team')" wire:navigate>Team Attendance</flux:sidebar.item>
                        @endif
                        @can('approve_leave')
                            <flux:sidebar.item :href="route('attendance.employees')" :current="request()->routeIs('attendance.employees')" wire:navigate>All Attendance</flux:sidebar.item>
                            <flux:sidebar.item :href="route('attendance.biometric-summary')" :current="request()->routeIs('attendance.biometric-summary')" wire:navigate>Biometric Summary</flux:sidebar.item>
                        @endcan
                        @can('manage_settings')
                            <flux:sidebar.item :href="route('attendance.settings')" :current="request()->routeIs('attendance.settings')" wire:navigate>Attendance Settings</flux:sidebar.item>
                        @endcan
                        <flux:sidebar.item :href="route('time-off.my')" :current="request()->routeIs('time-off.my')" wire:navigate>My Leave</flux:sidebar.item>
                        @if($isMgr || $user->hasPermission('approve_leave'))
                            <flux:sidebar.item :href="route('time-off.team')" :current="request()->routeIs('time-off.team')" wire:navigate>Team Leave</flux:sidebar.item>
                        @endif
                        @can('approve_leave')
                            <flux:sidebar.item :href="route('time-off.employees')" :current="request()->routeIs('time-off.employees')" wire:navigate>All Leave</flux:sidebar.item>
                        @endcan
                        @if($isFin || $isHr)
                            <flux:sidebar.item :href="route('time-off.encashments')" :current="request()->routeIs('time-off.encashments')" wire:navigate>Encashments</flux:sidebar.item>
                        @endif
                        @can('manage_settings')
                            <flux:sidebar.item :href="route('time-off.bulk-assign')" :current="request()->routeIs('time-off.bulk-assign')" wire:navigate>Bulk Leave</flux:sidebar.item>
                            <flux:sidebar.item :href="route('time-off.leave-policies')" :current="request()->routeIs('time-off.leave-policies')" wire:navigate>Leave Policies</flux:sidebar.item>
                        @endcan
                        @canany(['manage_leave_types', 'manage_leave_policies'])
                            <flux:sidebar.item :href="route('time-off.settings')" :current="request()->routeIs('time-off.settings')" wire:navigate>Leave Settings</flux:sidebar.item>
                        @endcanany
                        <flux:sidebar.item :href="route('overtime.my')" :current="request()->routeIs('overtime.my')" wire:navigate>My Overtime</flux:sidebar.item>
                        @can('approve_overtime')
                            <flux:sidebar.item :href="route('overtime.manage')" :current="request()->routeIs('overtime.manage')" wire:navigate>Approve OT</flux:sidebar.item>
                        @endcan
                        <flux:sidebar.item :href="route('wfh.my')" :current="request()->routeIs('wfh.my')" wire:navigate>My WFH</flux:sidebar.item>
                        @can('approve_wfh')
                            <flux:sidebar.item :href="route('wfh.manage')" :current="request()->routeIs('wfh.manage')" wire:navigate>Approve WFH</flux:sidebar.item>
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
                            <flux:sidebar.item :href="route('payroll.structures')"
                                :current="request()->routeIs('payroll.structures')" wire:navigate>Salary Structures</flux:sidebar.item>
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

                {{-- AI Assistant — shown for any role enabled in AI settings (all branches) --}}
                @if(auth()->user() && app(\App\Services\AiAssistant::class)->enabledForUser(auth()->user()))
                    <flux:sidebar.item icon="sparkles" :href="route('ai.assistant')" :current="request()->routeIs('ai.assistant')" wire:navigate>
                        AI Assistant
                    </flux:sidebar.item>
                @endif
            @endauth
        </flux:sidebar.nav>

        <flux:spacer />

        @can('manage_settings')
            <flux:sidebar.nav class="px-2 pb-1">
                <flux:sidebar.group heading="Settings" icon="cog-6-tooth" :expandable="true"
                    :expanded="request()->routeIs('settings.*')">
                    <flux:sidebar.item :href="route('settings.control-panel')" :current="request()->routeIs('settings.control-panel')"
                        wire:navigate>Control Panel</flux:sidebar.item>
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
                    <flux:sidebar.item :href="route('settings.notifications')"
                        :current="request()->routeIs('settings.notifications')" wire:navigate>Notifications &amp; Email</flux:sidebar.item>
                    <flux:sidebar.item :href="route('settings.menu')"
                        :current="request()->routeIs('settings.menu')" wire:navigate>Sidebar Menu</flux:sidebar.item>
                    <flux:sidebar.item :href="route('settings.audit-log')"
                        :current="request()->routeIs('settings.audit-log')" wire:navigate>Audit Log</flux:sidebar.item>
                </flux:sidebar.group>
            </flux:sidebar.nav>
        @endcan

        {{-- Meet Pulse AI promo --}}
        @if(Route::has('ai.assistant'))
            <div class="px-3 pb-1 pt-1">
                <div class="overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-orange-400 p-4 text-white shadow-lg shadow-orange-500/20">
                    <div class="flex items-center gap-2 text-[13px] font-bold">
                        <flux:icon.sparkles class="size-4" /> Meet Pulse AI
                    </div>
                    <p class="mt-1 text-[11px] leading-snug text-white/85">Get instant insights and automate HR tasks.</p>
                    <a href="{{ route('ai.assistant') }}" wire:navigate
                        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-white/95 px-3 py-2 text-xs font-bold text-orange-600 transition hover:bg-white">
                        <flux:icon.cpu-chip class="size-4" /> Ask AI Assistant
                    </a>
                </div>
            </div>
        @endif

        {{-- User profile --}}
        <div class="mt-1 border-t border-[#F3E8DD] px-3 pb-3 pt-3">
            <flux:dropdown position="top" align="start" class="w-full">
                <button type="button"
                    class="flex w-full items-center gap-3 rounded-2xl border border-[#F3E8DD] bg-white p-2.5 text-left shadow-sm transition hover:bg-[#FFF2E8]">
                    <div class="relative shrink-0">
                        <div class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-400 text-[13px] font-bold text-white">
                            {{ auth()->user()->initials() }}
                        </div>
                        <span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white bg-emerald-500"
                            title="Online"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="truncate text-[13px] font-bold text-[#111827]">{{ auth()->user()->name }}</div>
                        <div class="truncate text-[11px] font-semibold" style="color: {{ $roleColor }}">{{ $roleLabel }}</div>
                    </div>
                    <flux:icon.chevron-up-down class="size-4 shrink-0 text-[#9CA3AF]" />
                </button>
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
                    <flux:menu.item :href="route('settings.preferences')" icon="adjustments-horizontal" wire:navigate>Preferences</flux:menu.item>
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

    {{-- TOP HEADER — premium, glassy, role-aware accent --}}
    <flux:header
        style="--color-accent: {{ $roleColor }}; --color-accent-content: {{ $roleColor }};"
        class="border-b border-zinc-200/70 bg-white/75 backdrop-blur-xl dark:border-zinc-800/70 dark:bg-zinc-950/70">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        {{-- Search --}}
        <div class="ms-2 hidden w-full max-w-sm flex-1 items-center gap-2 lg:flex" x-data>
            <flux:input placeholder="{{ __('Search anything...') }}" icon="magnifying-glass" size="sm"
                class="w-full cursor-pointer" kbd="⌘ K" readonly @click="$flux.modal('global-search').show()" />
        </div>

        <flux:spacer />

        <div class="flex items-center gap-0.5">
            {{-- Mobile search --}}
            <flux:tooltip :content="__('Search')" position="bottom">
                <flux:button x-data icon="magnifying-glass" variant="subtle" size="sm" square
                    class="lg:hidden" @click="$flux.modal('global-search').show()" aria-label="{{ __('Search') }}" />
            </flux:tooltip>

            {{-- Dark / light toggle — single icon, no text --}}
            <flux:tooltip :content="__('Toggle theme')" position="bottom">
                <flux:button x-data x-on:click="$flux.dark = ! $flux.dark"
                    variant="subtle" size="sm" square aria-label="{{ __('Toggle theme') }}">
                    <flux:icon.moon x-show="!$flux.dark" x-cloak class="size-5" />
                    <flux:icon.sun x-show="$flux.dark" x-cloak class="size-5" />
                </flux:button>
            </flux:tooltip>

            {{-- Notifications --}}
            <livewire:notifications />

            {{-- Divider --}}
            <div class="mx-1.5 h-6 w-px bg-zinc-200 dark:bg-zinc-800"></div>

            {{-- Profile --}}
            <flux:dropdown position="bottom" align="end">
                <button type="button"
                    class="flex items-center gap-2.5 rounded-xl py-1 pe-2 ps-1 transition hover:bg-zinc-100 dark:hover:bg-zinc-800/60">
                    <flux:avatar :initials="auth()->user()->initials()" size="sm"
                        style="background-color: {{ $roleColor }}" class="text-white" />
                    <div class="hidden text-start leading-tight sm:block">
                        <div class="max-w-[120px] truncate text-[13px] font-bold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</div>
                        <div class="text-[10px] font-semibold uppercase tracking-wide" style="color: {{ $roleColor }}">{{ $roleLabel }}</div>
                    </div>
                    <flux:icon.chevron-down class="size-4 text-zinc-400" />
                </button>
                <flux:menu class="w-56">
                    <div class="flex items-center gap-3 px-2 py-2">
                        <flux:avatar :initials="auth()->user()->initials()" size="sm"
                            style="background-color: {{ $roleColor }}" class="text-white" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                        </div>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>Profile</flux:menu.item>
                    <flux:menu.item :href="route('settings.preferences')" icon="adjustments-horizontal" wire:navigate>Preferences</flux:menu.item>
                    <flux:menu.item :href="route('appearance.edit')" icon="paint-brush" wire:navigate>Appearance</flux:menu.item>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">Log out</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </div>
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

    {{-- "View as" impersonation banner --}}
    @if(session('impersonator_id'))
        <div class="fixed inset-x-0 bottom-4 z-50 flex justify-center px-4">
            <div class="flex items-center gap-3 rounded-full border border-amber-300 bg-amber-500 px-4 py-2 text-white shadow-lg shadow-amber-500/30">
                <flux:icon.eye class="size-4" />
                <span class="text-sm font-bold">Viewing as {{ auth()->user()->name }}</span>
                <a href="{{ route('impersonate.stop') }}"
                    class="rounded-full bg-white/95 px-3 py-1 text-xs font-black text-amber-700 transition hover:bg-white">
                    Exit to admin
                </a>
            </div>
        </div>
    @endif

    {{-- AI HR Copilot — renders nothing unless OPENAI_API_KEY is configured --}}
    @auth
        <livewire:ai-copilot />
    @endauth

    @fluxScripts
</body>

</html>