@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">

        @php
            $user  = auth()->user();
            $isEmp = $user->role?->value === 'employee';
            $isMgr = $user->isManager();
            $isHr  = $user->isHrAdmin() || $user->isSuperAdmin();
            $isFin = $user->canApproveFinance();
            $isDir = $user->role?->value === 'director';
            $isSA  = $user->isSuperAdmin();

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
                        <img src="{{ asset('storage/'.$company->logo) }}" class="size-6 object-contain" alt="{{ $company->name }}">
                    @else
                        <svg class="size-4 fill-white" viewBox="0 0 24 24"><path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/></svg>
                    @endif
                </x-slot>
            </flux:sidebar.brand>

            <flux:sidebar.nav class="px-2">
            @auth

            {{-- ════════════════════════════════════════════
                 EMPLOYEE SIDEBAR
                 ════════════════════════════════════════════ --}}
            @if($pureEmployee)

                {{-- Home --}}
                <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                    Dashboard
                </flux:sidebar.item>

                {{-- Inbox --}}
                <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')" wire:navigate>
                    <div class="flex items-center gap-2">
                        Inbox
                        @if($unread > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                        @endif
                    </div>
                </flux:sidebar.item>

                {{-- My Work --}}
                <flux:sidebar.group heading="My Work" icon="user-circle" :expandable="true"
                    :expanded="request()->routeIs('attendance.my', 'time-off.my', 'overtime.my')">
                    <flux:sidebar.item :href="route('attendance.my')" :current="request()->routeIs('attendance.my')" wire:navigate>
                        Attendance
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('time-off.my')" :current="request()->routeIs('time-off.my')" wire:navigate>
                        Leave
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('overtime.my')" :current="request()->routeIs('overtime.my')" wire:navigate>
                        Overtime
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Finance — reimbursements removed (requires run-payroll, 403 for employee) --}}
                <flux:sidebar.group heading="Finance" icon="banknotes" :expandable="true"
                    :expanded="request()->routeIs('payroll.payslips', 'operations.expenses')">
                    <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')" wire:navigate>
                        My Payslips
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('operations.expenses')" :current="request()->routeIs('operations.expenses')" wire:navigate>
                        Expense Claims
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Performance --}}
                <flux:sidebar.group heading="Performance" icon="arrow-trending-up" :expandable="true"
                    :expanded="request()->routeIs('performance.my', 'performance.goals')">
                    <flux:sidebar.item :href="route('performance.my')" :current="request()->routeIs('performance.my')" wire:navigate>
                        My Review
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')" wire:navigate>
                        My Goals
                    </flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Documents --}}
                <flux:sidebar.item icon="document-text" :href="route('documents.index')" :current="request()->routeIs('documents.*')" wire:navigate>
                    Documents
                </flux:sidebar.item>

                {{-- Company --}}
                <flux:sidebar.group heading="Company" icon="building-office" :expandable="true"
                    :expanded="request()->routeIs('employees.directory', 'employees.org-chart')">
                    <flux:sidebar.item :href="route('employees.directory')" :current="request()->routeIs('employees.directory')" wire:navigate>
                        Directory
                    </flux:sidebar.item>
                    <flux:sidebar.item :href="route('employees.org-chart')" :current="request()->routeIs('employees.org-chart')" wire:navigate>
                        Org Chart
                    </flux:sidebar.item>
                </flux:sidebar.group>

            {{-- ════════════════════════════════════════════
                 MANAGER SIDEBAR
                 ════════════════════════════════════════════ --}}
            @elseif($isMgr && !$isHr && !$isFin && !$isDir)

                <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:sidebar.item>
                <flux:sidebar.item icon="presentation-chart-line" :href="route('dashboard.manager')" :current="request()->routeIs('dashboard.manager')" wire:navigate>Team View</flux:sidebar.item>

                <flux:sidebar.group heading="My Work" icon="user-circle" :expandable="true"
                    :expanded="request()->routeIs('attendance.my', 'time-off.my', 'overtime.my', 'payroll.payslips', 'operations.expenses')">
                    <flux:sidebar.item :href="route('attendance.my')"       :current="request()->routeIs('attendance.my')"       wire:navigate>My Attendance</flux:sidebar.item>
                    <flux:sidebar.item :href="route('time-off.my')"         :current="request()->routeIs('time-off.my')"         wire:navigate>My Leave</flux:sidebar.item>
                    <flux:sidebar.item :href="route('overtime.my')"         :current="request()->routeIs('overtime.my')"         wire:navigate>My Overtime</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.payslips')"    :current="request()->routeIs('payroll.payslips')"    wire:navigate>My Payslip</flux:sidebar.item>
                    <flux:sidebar.item :href="route('operations.expenses')" :current="request()->routeIs('operations.expenses')" wire:navigate>Expense Claims</flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group heading="My Team" icon="users" :expandable="true"
                    :expanded="request()->routeIs('attendance.team', 'time-off.team', 'performance.team', 'overtime.manage')">
                    <flux:sidebar.item :href="route('attendance.team')"  :current="request()->routeIs('attendance.team')"  wire:navigate>Team Attendance</flux:sidebar.item>
                    <flux:sidebar.item :href="route('time-off.team')"    :current="request()->routeIs('time-off.team')"    wire:navigate>Team Leave</flux:sidebar.item>
                    <flux:sidebar.item :href="route('performance.team')" :current="request()->routeIs('performance.team')" wire:navigate>Team Reviews</flux:sidebar.item>
                    <flux:sidebar.item :href="route('overtime.manage')"  :current="request()->routeIs('overtime.manage')"  wire:navigate>Overtime Requests</flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group heading="Performance" icon="arrow-trending-up" :expandable="true"
                    :expanded="request()->routeIs('performance.my', 'performance.goals')">
                    <flux:sidebar.item :href="route('performance.my')"    :current="request()->routeIs('performance.my')"    wire:navigate>My Review</flux:sidebar.item>
                    <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')" wire:navigate>My Goals</flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.item icon="document-text" :href="route('documents.index')" :current="request()->routeIs('documents.*')" wire:navigate>Documents</flux:sidebar.item>

                <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')">
                    <div class="flex items-center gap-2">
                        Inbox
                        @if($unread > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                        @endif
                    </div>
                </flux:sidebar.item>

            {{-- ════════════════════════════════════════════
                 FINANCE SIDEBAR
                 ════════════════════════════════════════════ --}}
            @elseif($isFin && !$isHr)

                <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>Dashboard</flux:sidebar.item>
                <flux:sidebar.item icon="banknotes" :href="route('dashboard.finance')" :current="request()->routeIs('dashboard.finance')" wire:navigate>Finance View</flux:sidebar.item>

                <flux:sidebar.group heading="Payroll" icon="banknotes" :expandable="true" :expanded="request()->routeIs('payroll.*')">
                    <flux:sidebar.item :href="route('payroll.finance-approve')" :current="request()->routeIs('payroll.finance-approve')" wire:navigate>Finance Approval</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.incentives')"      :current="request()->routeIs('payroll.incentives')"      wire:navigate>Incentives</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.reimbursements')"  :current="request()->routeIs('payroll.reimbursements')"  wire:navigate>Reimbursements</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.payslips')"        :current="request()->routeIs('payroll.payslips')"        wire:navigate>My Payslip</flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.group heading="My Work" icon="user-circle" :expandable="true"
                    :expanded="request()->routeIs('attendance.my', 'time-off.my', 'operations.expenses')">
                    <flux:sidebar.item :href="route('attendance.my')"       :current="request()->routeIs('attendance.my')"       wire:navigate>My Attendance</flux:sidebar.item>
                    <flux:sidebar.item :href="route('time-off.my')"         :current="request()->routeIs('time-off.my')"         wire:navigate>My Leave</flux:sidebar.item>
                    <flux:sidebar.item :href="route('operations.expenses')" :current="request()->routeIs('operations.expenses')" wire:navigate>Expense Claims</flux:sidebar.item>
                </flux:sidebar.group>

                <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')">
                    <div class="flex items-center gap-2">
                        Inbox
                        @if($unread > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                        @endif
                    </div>
                </flux:sidebar.item>

            {{-- ════════════════════════════════════════════
                 HR ADMIN / SUPER ADMIN / DIRECTOR SIDEBAR
                 ════════════════════════════════════════════ --}}
            @else

                <flux:sidebar.item icon="squares-2x2" :href="route('dashboard')" :current="request()->routeIs('dashboard') && !request()->routeIs('dashboard.*')" wire:navigate>Dashboard</flux:sidebar.item>
                @if($isSA || $isDir)
                <flux:sidebar.item icon="chart-bar-square" :href="route('dashboard.executive')" :current="request()->routeIs('dashboard.executive')" wire:navigate>Executive View</flux:sidebar.item>
                @endif
                @if($isHr)
                <flux:sidebar.item icon="user-group" :href="route('dashboard.hr-admin')" :current="request()->routeIs('dashboard.hr-admin')" wire:navigate>HR Overview</flux:sidebar.item>
                @endif
                @if($user->isDepartmentHead())
                <flux:sidebar.item icon="building-office" :href="route('dashboard.department')" :current="request()->routeIs('dashboard.department')" wire:navigate>Department View</flux:sidebar.item>
                @endif

                {{-- People --}}
                @if($isHr)
                <flux:sidebar.group heading="People" icon="users" :expandable="true" :expanded="request()->routeIs('employees.*')">
                    @if(Route::has('employees.index'))
                    <flux:sidebar.item :href="route('employees.index')"               :current="request()->routeIs('employees.index')"               wire:navigate>Manage Employees</flux:sidebar.item>
                    @endif
                    @if(Route::has('employees.onboarding-manager'))
                    <flux:sidebar.item :href="route('employees.onboarding-manager')"  :current="request()->routeIs('employees.onboarding-manager')"  wire:navigate>Onboarding</flux:sidebar.item>
                    @endif
                    @if(Route::has('employees.offboarding-manager'))
                    <flux:sidebar.item :href="route('employees.offboarding-manager')" :current="request()->routeIs('employees.offboarding-manager')" wire:navigate>Offboarding</flux:sidebar.item>
                    @endif
                    @if(Route::has('employees.directory'))
                    <flux:sidebar.item :href="route('employees.directory')"           :current="request()->routeIs('employees.directory')"           wire:navigate>Directory</flux:sidebar.item>
                    @endif
                    @if(Route::has('employees.org-chart'))
                    <flux:sidebar.item :href="route('employees.org-chart')"           :current="request()->routeIs('employees.org-chart')"           wire:navigate>Org Chart</flux:sidebar.item>
                    @endif
                </flux:sidebar.group>
                @elseif($isDir)
                <flux:sidebar.group heading="Company" icon="building-office" :expandable="true"
                    :expanded="request()->routeIs('employees.directory', 'employees.org-chart')">
                    <flux:sidebar.item :href="route('employees.directory')" :current="request()->routeIs('employees.directory')" wire:navigate>Directory</flux:sidebar.item>
                    <flux:sidebar.item :href="route('employees.org-chart')" :current="request()->routeIs('employees.org-chart')" wire:navigate>Org Chart</flux:sidebar.item>
                </flux:sidebar.group>
                @endif

                {{-- Time & Attendance --}}
                <flux:sidebar.group heading="Time & Attendance" icon="calendar-days" :expandable="true"
                    :expanded="request()->routeIs('attendance.*', 'time-off.*', 'overtime.*')">
                    <flux:sidebar.item :href="route('attendance.my')" :current="request()->routeIs('attendance.my')" wire:navigate>My Attendance</flux:sidebar.item>
                    <flux:sidebar.item :href="route('time-off.my')"   :current="request()->routeIs('time-off.my')"   wire:navigate>My Leave</flux:sidebar.item>
                    @if($user->canApproveLeave())
                    <flux:sidebar.item :href="route('attendance.employees')" :current="request()->routeIs('attendance.employees')" wire:navigate>All Attendance</flux:sidebar.item>
                    <flux:sidebar.item :href="route('time-off.employees')"   :current="request()->routeIs('time-off.employees')"   wire:navigate>All Leave</flux:sidebar.item>
                    @endif
                    @if($isMgr || $user->canApproveLeave())
                    <flux:sidebar.item :href="route('time-off.team')"    :current="request()->routeIs('time-off.team')"    wire:navigate>Team Leave</flux:sidebar.item>
                    <flux:sidebar.item :href="route('attendance.team')"  :current="request()->routeIs('attendance.team')"  wire:navigate>Team Attendance</flux:sidebar.item>
                    @endif
                    @if($user->canApproveOt())
                    <flux:sidebar.item :href="route('overtime.manage')" :current="request()->routeIs('overtime.manage')" wire:navigate>Overtime</flux:sidebar.item>
                    @endif
                    @if($user->canManageSettings())
                    <flux:sidebar.item :href="route('time-off.settings')"   :current="request()->routeIs('time-off.settings')"   wire:navigate>Leave Settings</flux:sidebar.item>
                    <flux:sidebar.item :href="route('attendance.settings')" :current="request()->routeIs('attendance.settings')" wire:navigate>Attendance Settings</flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                {{-- Payroll --}}
                <flux:sidebar.group heading="Payroll" icon="banknotes" :expandable="true" :expanded="request()->routeIs('payroll.*')">
                    <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')" wire:navigate>My Payslip</flux:sidebar.item>
                    @if($user->canRunPayroll())
                    <flux:sidebar.item :href="route('payroll.overview')"   :current="request()->routeIs('payroll.overview')"   wire:navigate>Overview</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.process')"    :current="request()->routeIs('payroll.process')"    wire:navigate>Run Payroll</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.components')" :current="request()->routeIs('payroll.components')" wire:navigate>Components</flux:sidebar.item>
                    @endif
                    @if($isFin || $isHr)
                    <flux:sidebar.item :href="route('payroll.incentives')"     :current="request()->routeIs('payroll.incentives')"     wire:navigate>Incentives</flux:sidebar.item>
                    <flux:sidebar.item :href="route('payroll.reimbursements')" :current="request()->routeIs('payroll.reimbursements')" wire:navigate>Reimbursements</flux:sidebar.item>
                    @endif
                    @if($isFin)
                    <flux:sidebar.item :href="route('payroll.finance-approve')" :current="request()->routeIs('payroll.finance-approve')" wire:navigate>Finance Approval</flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                {{-- Performance --}}
                <flux:sidebar.group heading="Performance" icon="arrow-trending-up" :expandable="true" :expanded="request()->routeIs('performance.*')">
                    <flux:sidebar.item :href="route('performance.my')"    :current="request()->routeIs('performance.my')"    wire:navigate>My Review</flux:sidebar.item>
                    <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')" wire:navigate>My Goals</flux:sidebar.item>
                    @if($isMgr || $user->canApproveLeave())
                    <flux:sidebar.item :href="route('performance.team')"  :current="request()->routeIs('performance.team')"  wire:navigate>Team Reviews</flux:sidebar.item>
                    @endif
                    @if($user->canManageEmployees())
                    <flux:sidebar.item :href="route('performance.employees')" :current="request()->routeIs('performance.employees')" wire:navigate>All Reviews</flux:sidebar.item>
                    <flux:sidebar.item :href="route('performance.cycles')"    :current="request()->routeIs('performance.cycles')"    wire:navigate>Review Cycles</flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                {{-- Operations --}}
                <flux:sidebar.group heading="Operations" icon="building-office-2" :expandable="true"
                    :expanded="request()->routeIs('operations.*', 'documents.*')">
                    @if($user->canManageEmployees())
                    <flux:sidebar.item :href="route('operations.assets')" :current="request()->routeIs('operations.assets')" wire:navigate>Assets</flux:sidebar.item>
                    @endif
                    <flux:sidebar.item :href="route('operations.expenses')" :current="request()->routeIs('operations.expenses')" wire:navigate>Expense Claims</flux:sidebar.item>
                    <flux:sidebar.item :href="route('documents.index')"     :current="request()->routeIs('documents.*')"          wire:navigate>Documents</flux:sidebar.item>
                </flux:sidebar.group>

                {{-- Inbox --}}
                <flux:sidebar.item icon="inbox" href="{{ $inboxRoute }}" :current="request()->routeIs('notifications.*')">
                    <div class="flex items-center gap-2">
                        Inbox
                        @if($unread > 0)
                            <span class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $unread > 9 ? '9+' : $unread }}</span>
                        @endif
                    </div>
                </flux:sidebar.item>

            @endif
            @endauth
            </flux:sidebar.nav>

            <flux:spacer />

            @if($user->canManageSettings())
            <flux:sidebar.nav class="px-2 pb-1">
                <flux:sidebar.item icon="cog-6-tooth" :href="route('settings.general')" :current="request()->routeIs('settings.*')" wire:navigate>Settings</flux:sidebar.item>
            </flux:sidebar.nav>
            @endif

            {{-- User profile --}}
            <div class="mt-1 border-t border-zinc-100 px-2 pb-2 pt-2 dark:border-zinc-800">
                <flux:dropdown position="top" align="start" class="w-full">
                    <flux:sidebar.profile :name="auth()->user()->name" :initials="auth()->user()->initials()" icon:trailing="chevrons-up-down" />
                    <flux:menu class="w-56">
                        <div class="flex items-center gap-3 px-2 py-2">
                            <flux:avatar :initials="auth()->user()->initials()" size="sm" class="bg-orange-500 text-white" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                            </div>
                        </div>
                        <div class="px-3 pb-2">
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-bold
                                {{ $isHr ? 'bg-violet-100 text-violet-700' : ($isFin ? 'bg-emerald-100 text-emerald-700' : ($isMgr ? 'bg-blue-100 text-blue-700' : 'bg-zinc-100 text-zinc-600')) }}">
                                {{ ucwords(str_replace('_', ' ', $user->role?->value ?? 'Employee')) }}
                            </span>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>Profile</flux:menu.item>
                        @if($user->canManageSettings())
                        <flux:menu.item :href="route('settings.general')" icon="cog-6-tooth" wire:navigate>Settings</flux:menu.item>
                        @endif
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full text-red-600 dark:text-red-400">Log out</flux:menu.item>
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
                <flux:profile :name="auth()->user()->name" :initials="auth()->user()->initials()" class="ms-1 cursor-pointer" />
                <flux:menu class="w-52">
                    <div class="flex items-center gap-2 px-2 py-2">
                        <flux:avatar :initials="auth()->user()->initials()" size="sm" class="bg-orange-500 text-white" />
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
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">Log out</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        <flux:modal name="global-search" class="w-full max-w-2xl">
            <div
                x-data="{ q: '' }"
                x-on:keydown.escape.window="$flux.modal('global-search').close()"
                class="space-y-5"
            >
                <div>
                    <flux:heading size="lg">Search Anything</flux:heading>
                    <flux:subheading>Jump quickly to the most-used pages in Pulse.</flux:subheading>
                </div>

                <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex items-center gap-3">
                        <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                        <input
                            x-model="q"
                            type="text"
                            placeholder="Search dashboard, attendance, leave, payroll..."
                            class="w-full border-0 bg-transparent p-0 text-sm text-zinc-900 outline-none placeholder:text-zinc-400 focus:ring-0 dark:text-white"
                        >
                    </div>
                </div>

                <div class="max-h-[420px] space-y-2 overflow-y-auto">
                    @foreach($searchLinks as $item)
                        <a
                            href="{{ $item['route'] }}"
                            wire:navigate
                            x-show="'{{ \Illuminate\Support\Str::lower($item['label'].' '.$item['caption']) }}'.includes(q.toLowerCase())"
                            class="block rounded-2xl border border-zinc-200 bg-white px-4 py-3 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-zinc-700 dark:hover:bg-zinc-900"
                        >
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

        @fluxScripts
    </body>
</html>
