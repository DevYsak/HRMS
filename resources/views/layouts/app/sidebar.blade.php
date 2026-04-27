@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-zinc-50 antialiased dark:bg-zinc-950">

        @php
            $user    = auth()->user();
            $isEmp   = $user->role?->value === 'employee';
            $isMgr   = $user->isManager();
            $isHr    = $user->isHrAdmin() || $user->isSuperAdmin();
            $isFin   = $user->canApproveFinance();
            $isDir   = $user->role?->value === 'director';

            // Unread notifications count for the sidebar Inbox badge
            $unreadNotifications = $user->unreadNotifications()->count();

            // Prefer a dedicated notifications route if available
            if (Route::has('notifications.index')) {
                $inboxRoute = route('notifications.index');
            } elseif (Route::has('notifications')) {
                $inboxRoute = route('notifications');
            } else {
                $inboxRoute = '#';
            }
        @endphp

        {{-- =====================================================
             SIDEBAR
             ===================================================== --}}
        <flux:sidebar sticky collapsible class="border-e border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">

            {{-- Brand --}}
            <flux:sidebar.brand
                name="Pulse HRMS"
                :href="route('dashboard')"
                wire:navigate
            >
                <x-slot name="logo" class="flex aspect-square size-8 items-center justify-center rounded-lg bg-indigo-600">
                    <svg class="size-4 fill-white" viewBox="0 0 24 24"><path d="M4 3h3v7h10V3h3v18h-3v-8H7v8H4V3z"/></svg>
                </x-slot>
            </flux:sidebar.brand>

            <flux:sidebar.nav class="px-2">

                {{-- ============================================
                     EMPLOYEE SIDEBAR (Keka-style simplified)
                     ============================================ --}}
                @auth
                @if(auth()->user()->role?->value === 'employee' && !auth()->user()->isManager() && !auth()->user()->isHrAdmin() && !auth()->user()->canApproveFinance() && auth()->user()->role?->value !== 'director')

                    {{-- Home / Dashboard --}}
                    <flux:sidebar.item
                        icon="home"
                        :href="route('dashboard')"
                        :current="request()->routeIs('dashboard') && !request()->routeIs('dashboard.*')"
                        wire:navigate
                    >
                        {{ __('Home') }}
                    </flux:sidebar.item>

                    {{-- Me → My self-service group --}}
                    <flux:sidebar.group
                        heading="{{ __('Me') }}"
                        icon="user-circle"
                        :expandable="true"
                        :expanded="request()->routeIs('attendance.my') || request()->routeIs('time-off.my') || request()->routeIs('overtime.my')"
                    >
                        <flux:sidebar.item
                            :href="route('attendance.my')"
                            :current="request()->routeIs('attendance.my')"
                            wire:navigate
                        >{{ __('Attendance') }}</flux:sidebar.item>

                        <flux:sidebar.item
                            :href="route('time-off.my')"
                            :current="request()->routeIs('time-off.my')"
                            wire:navigate
                        >{{ __('Leave') }}</flux:sidebar.item>

                        <flux:sidebar.item
                            :href="route('overtime.my')"
                            :current="request()->routeIs('overtime.my')"
                            wire:navigate
                        >{{ __('Overtime') }}</flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Performance — top-level main nav --}}
                    <flux:sidebar.group
                        heading="{{ __('Performance') }}"
                        icon="arrow-trending-up"
                        :expandable="true"
                        :expanded="request()->routeIs('performance.my') || request()->routeIs('performance.goals')"
                    >
                        <flux:sidebar.item :href="route('performance.my')"    :current="request()->routeIs('performance.my')"    wire:navigate>{{ __('My Review') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')" wire:navigate>{{ __('Goals') }}</flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Expenses & Travel — top-level main nav --}}
                    <flux:sidebar.group
                        :heading="'Expenses & Travel'"
                        icon="receipt-percent"
                        :expandable="true"
                        :expanded="request()->routeIs('operations.*') || request()->routeIs('payroll.reimbursements')"
                    >
                        <flux:sidebar.item :href="route('operations.expenses')" :current="request()->routeIs('operations.expenses')" wire:navigate>{{ __('Expense Claims') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('operations.assets')"   :current="request()->routeIs('operations.assets')"   wire:navigate>{{ __('My Assets') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('payroll.reimbursements')" :current="request()->routeIs('payroll.reimbursements')" wire:navigate>{{ __('Reimbursements') }}</flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Inbox / Notifications --}}
                    <flux:sidebar.item
                        icon="inbox"
                        href="{{ $inboxRoute }}"
                        :current="request()->routeIs('notifications.*')"
                    >
                        <div class="flex items-center gap-2">
                            <span>{{ __('Inbox') }}</span>
                            @if($unreadNotifications > 0)
                                <span class="inline-flex items-center justify-center px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-500 text-white">{{ $unreadNotifications > 9 ? '9+' : $unreadNotifications }}</span>
                            @endif
                        </div>
                    </flux:sidebar.item>

                    {{-- My Finances --}}
                    <flux:sidebar.group
                        heading="{{ __('My Finances') }}"
                        icon="banknotes"
                        :expandable="true"
                        :expanded="request()->routeIs('payroll.payslips') || request()->routeIs('payroll.reimbursements')"
                    >
                        <flux:sidebar.item :href="route('payroll.payslips')"      :current="request()->routeIs('payroll.payslips')"      wire:navigate>{{ __('My Payslips') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('payroll.reimbursements')" :current="request()->routeIs('payroll.reimbursements')" wire:navigate>{{ __('Reimbursements') }}</flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Org --}}
                    <flux:sidebar.group
                        heading="{{ __('Org') }}"
                        icon="building-office"
                        :expandable="true"
                        :expanded="request()->routeIs('employees.directory') || request()->routeIs('employees.org-chart')"
                    >
                        <flux:sidebar.item :href="route('employees.org-chart')"  :current="request()->routeIs('employees.org-chart')"  wire:navigate>{{ __('Org Chart') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('employees.directory')"  :current="request()->routeIs('employees.directory')"  wire:navigate>{{ __('Directory') }}</flux:sidebar.item>
                    </flux:sidebar.group>

                    {{-- Documents --}}
                    <flux:sidebar.item
                        icon="document-text"
                        :href="route('documents.index')"
                        :current="request()->routeIs('documents.*')"
                        wire:navigate
                    >
                        {{ __('Documents') }}
                    </flux:sidebar.item>

                @else

                    {{-- ============================================
                         ADMIN / MANAGER / FINANCE SIDEBAR
                         ============================================ --}}

                    {{-- Dashboard --}}
                    <flux:sidebar.item
                        icon="squares-2x2"
                        :href="route('dashboard')"
                        :current="request()->routeIs('dashboard') && !request()->routeIs('dashboard.*')"
                        wire:navigate
                    >
                        {{ __('Dashboard') }}
                    </flux:sidebar.item>

                    {{-- Role-specific dashboard shortcuts --}}
                    @if($isDir || $user->isSuperAdmin())
                    <flux:sidebar.item icon="chart-bar-square" :href="route('dashboard.executive')" :current="request()->routeIs('dashboard.executive')" wire:navigate>
                        {{ __('Executive View') }}
                    </flux:sidebar.item>
                    @endif

                    @if($isFin)
                    <flux:sidebar.item icon="banknotes" :href="route('dashboard.finance')" :current="request()->routeIs('dashboard.finance')" wire:navigate>
                        {{ __('Finance View') }}
                    </flux:sidebar.item>
                    @endif

                    @if($isHr)
                    <flux:sidebar.item icon="user-group" :href="route('dashboard.hr-admin')" :current="request()->routeIs('dashboard.hr-admin')" wire:navigate>
                        {{ __('HR View') }}
                    </flux:sidebar.item>
                    @endif

                    @if($isMgr)
                    <flux:sidebar.item icon="presentation-chart-line" :href="route('dashboard.manager')" :current="request()->routeIs('dashboard.manager')" wire:navigate>
                        {{ __('Team View') }}
                    </flux:sidebar.item>
                    @endif

                    @if($user->isDepartmentHead())
                    <flux:sidebar.item icon="building-office" :href="route('dashboard.department')" :current="request()->routeIs('dashboard.department')" wire:navigate>
                        {{ __('Department View') }}
                    </flux:sidebar.item>
                    @endif

                    {{-- Employees (HR/Admin only) --}}
                    @if($isHr)
                    <flux:sidebar.group heading="{{ __('Employees') }}" icon="users" :expandable="true" :expanded="request()->routeIs('employees.*')">
                        <flux:sidebar.item :href="route('employees.index')"     :current="request()->routeIs('employees.index')"     wire:navigate>{{ __('Manage') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('employees.directory')" :current="request()->routeIs('employees.directory')" wire:navigate>{{ __('Directory') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('employees.org-chart')" :current="request()->routeIs('employees.org-chart')" wire:navigate>{{ __('Org Chart') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('employees.offboarding-manager')" :current="request()->routeIs('employees.offboarding-manager')" wire:navigate>{{ __('Offboarding') }}</flux:sidebar.item>
                    </flux:sidebar.group>
                    @endif

                    {{-- Time Off --}}
                    <flux:sidebar.group heading="{{ __('Time Off') }}" icon="clock" :expandable="true" :expanded="request()->routeIs('time-off.*')">
                        <flux:sidebar.item :href="route('time-off.my')"   :current="request()->routeIs('time-off.my')"   wire:navigate>{{ __('My Time Off') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('time-off.team')" :current="request()->routeIs('time-off.team')" wire:navigate>{{ __('Team Time Off') }}</flux:sidebar.item>
                        @if($user->canApproveLeave())
                            <flux:sidebar.item :href="route('time-off.employees')" :current="request()->routeIs('time-off.employees')" wire:navigate>{{ __('Employee Time Off') }}</flux:sidebar.item>
                        @endif
                        @if($user->canManageSettings())
                            <flux:sidebar.item :href="route('time-off.settings')"  :current="request()->routeIs('time-off.settings')"  wire:navigate>{{ __('Settings') }}</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- Attendance --}}
                    <flux:sidebar.group heading="{{ __('Attendance') }}" icon="calendar-days" :expandable="true" :expanded="request()->routeIs('attendance.*')">
                        <flux:sidebar.item :href="route('attendance.my')"   :current="request()->routeIs('attendance.my')"   wire:navigate>{{ __('My Attendance') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('attendance.team')" :current="request()->routeIs('attendance.team')" wire:navigate>{{ __('Team Attendance') }}</flux:sidebar.item>
                        @if($user->canManageEmployees())
                            <flux:sidebar.item :href="route('attendance.employees')" :current="request()->routeIs('attendance.employees')" wire:navigate>{{ __('Employee Attendance') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('attendance.settings')"  :current="request()->routeIs('attendance.settings')"  wire:navigate>{{ __('Settings') }}</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- Overtime --}}
                    <flux:sidebar.group heading="{{ __('Overtime') }}" icon="clock" :expandable="true" :expanded="request()->routeIs('overtime.*')">
                        <flux:sidebar.item :href="route('overtime.my')" :current="request()->routeIs('overtime.my')" wire:navigate>{{ __('My OT Requests') }}</flux:sidebar.item>
                        @if($user->canApproveOt())
                            <flux:sidebar.item :href="route('overtime.manage')" :current="request()->routeIs('overtime.manage')" wire:navigate>{{ __('Manage OT') }}</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- Payroll --}}
                    <flux:sidebar.group heading="{{ __('Payroll') }}" icon="banknotes" :expandable="true" :expanded="request()->routeIs('payroll.*')">
                        <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')" wire:navigate>{{ __('My Payslip') }}</flux:sidebar.item>
                        @if($user->canRunPayroll())
                            <flux:sidebar.item :href="route('payroll.overview')"   :current="request()->routeIs('payroll.overview')"   wire:navigate>{{ __('Overview') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('payroll.process')"    :current="request()->routeIs('payroll.process')"    wire:navigate>{{ __('Run Payroll') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('payroll.components')" :current="request()->routeIs('payroll.components')" wire:navigate>{{ __('Components') }}</flux:sidebar.item>
                        @endif
                        @if($isFin)
                            <flux:sidebar.item :href="route('payroll.finance-approve')" :current="request()->routeIs('payroll.finance-approve')" wire:navigate>{{ __('Finance Approval') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('payroll.incentives')"      :current="request()->routeIs('payroll.incentives')"      wire:navigate>{{ __('Incentives') }}</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- Performance --}}
                    <flux:sidebar.group heading="{{ __('Performance') }}" icon="arrow-trending-up" :expandable="true" :expanded="request()->routeIs('performance.*')">
                        <flux:sidebar.item :href="route('performance.my')"    :current="request()->routeIs('performance.my')"    wire:navigate>{{ __('My Review') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')" wire:navigate>{{ __('My Goals') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('performance.team')"  :current="request()->routeIs('performance.team')"  wire:navigate>{{ __('Team Reviews') }}</flux:sidebar.item>
                        @if($user->canManageEmployees())
                            <flux:sidebar.item :href="route('performance.employees')" :current="request()->routeIs('performance.employees')" wire:navigate>{{ __('All Reviews') }}</flux:sidebar.item>
                            <flux:sidebar.item :href="route('performance.cycles')"    :current="request()->routeIs('performance.cycles')"    wire:navigate>{{ __('Review Cycles') }}</flux:sidebar.item>
                        @endif
                    </flux:sidebar.group>

                    {{-- Operations --}}
                    <flux:sidebar.group heading="{{ __('Operations') }}" icon="building-office-2" :expandable="true" :expanded="request()->routeIs('operations.*') || request()->routeIs('documents.*')">
                        <flux:sidebar.item :href="route('operations.assets')"   :current="request()->routeIs('operations.assets')"   wire:navigate>{{ __('Assets') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('operations.expenses')" :current="request()->routeIs('operations.expenses')" wire:navigate>{{ __('Expenses') }}</flux:sidebar.item>
                        <flux:sidebar.item :href="route('documents.index')"     :current="request()->routeIs('documents.*')"          wire:navigate>{{ __('Documents') }}</flux:sidebar.item>
                    </flux:sidebar.group>

                @endif
                @endauth

            </flux:sidebar.nav>

            <flux:spacer />

            {{-- Bottom links --}}
            <flux:sidebar.nav class="px-2 pb-1">
                @if($user->canManageSettings())
                    <flux:sidebar.item
                        icon="cog-6-tooth"
                        :href="route('settings.general')"
                        :current="request()->routeIs('settings.*')"
                        wire:navigate
                    >
                        {{ __('Settings') }}
                    </flux:sidebar.item>
                @endif
            </flux:sidebar.nav>

            {{-- User profile --}}
            <div class="border-t border-zinc-100 dark:border-zinc-800 mt-1 pt-2 px-2 pb-2">
                <flux:dropdown position="top" align="start" class="w-full">
                    <flux:sidebar.profile
                        :name="auth()->user()->name"
                        :initials="auth()->user()->initials()"
                        icon:trailing="chevrons-up-down"
                    />
                    <flux:menu class="w-56">
                        <div class="flex items-center gap-3 px-2 py-2">
                            <flux:avatar :initials="auth()->user()->initials()" size="sm" class="bg-indigo-600 text-white" />
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</p>
                                <p class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                            </div>
                        </div>
                        {{-- Role badge --}}
                        <div class="px-3 pb-2">
                            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-bold
                                {{ $isHr ? 'bg-violet-100 text-violet-700' : ($isFin ? 'bg-emerald-100 text-emerald-700' : ($isMgr ? 'bg-blue-100 text-blue-700' : 'bg-zinc-100 text-zinc-600')) }}">
                                {{ ucfirst($user->role?->value ?? 'Employee') }}
                            </span>
                        </div>
                        <flux:menu.separator />
                        <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                        @if($user->canManageSettings())
                            <flux:menu.item :href="route('settings.general')" icon="cog-6-tooth" wire:navigate>{{ __('Settings') }}</flux:menu.item>
                        @endif
                        <flux:menu.separator />
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full text-red-600 dark:text-red-400">
                                {{ __('Log out') }}
                            </flux:menu.item>
                        </form>
                    </flux:menu>
                </flux:dropdown>
            </div>

        </flux:sidebar>

        {{-- =====================================================
             TOP HEADER
             ===================================================== --}}
        <flux:header class="border-b border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-950">

            {{-- Mobile sidebar toggle --}}
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            {{-- Search --}}
            <div class="hidden lg:flex items-center gap-2 ms-2 max-w-xs flex-1" x-data>
                <flux:input
                    placeholder="{{ __('Search anything...') }}"
                    icon="magnifying-glass"
                    size="sm"
                    class="w-full cursor-pointer"
                    kbd="⌘ K"
                    readonly
                    @click="$flux.modal('global-search').show()"
                />
            </div>

            {{-- Quick top-nav links (contextual by role) --}}
            <nav class="hidden lg:flex items-center gap-5 ms-6">
                @if($isEmp && !$isMgr && !$isHr && !$isFin)
                    <a href="{{ route('documents.index') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Documents</a>
                    <a href="{{ route('payroll.payslips') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Payslip</a>
                    <a href="{{ route('time-off.my') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Apply Leave</a>
                    <a href="{{ route('performance.my') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Performance</a>
                @else
                    <a href="{{ route('employees.index') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Employees</a>
                    <a href="{{ route('payroll.overview') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Payroll</a>
                    <a href="{{ route('time-off.employees') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Leave</a>
                    <a href="{{ route('performance.cycles') }}" wire:navigate class="text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition-colors">Performance</a>
                @endif
            </nav>

            <flux:spacer />

            {{-- Notifications --}}
            <div class="flex items-center gap-1">
                <livewire:notifications />
            </div>

            {{-- User avatar dropdown --}}
            <flux:dropdown position="bottom" align="end">
                <flux:profile
                    :name="auth()->user()->name"
                    :initials="auth()->user()->initials()"
                    class="cursor-pointer ms-1"
                />
                <flux:menu class="w-52">
                    <div class="flex items-center gap-2 px-2 py-2">
                        <flux:avatar :initials="auth()->user()->initials()" size="sm" class="bg-indigo-600 text-white" />
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</p>
                            <p class="truncate text-xs text-zinc-500">{{ auth()->user()->email }}</p>
                        </div>
                    </div>
                    <flux:menu.separator />
                    <flux:menu.item :href="route('profile.edit')" icon="user" wire:navigate>{{ __('Profile') }}</flux:menu.item>
                    <flux:menu.separator />
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle" class="w-full">{{ __('Log out') }}</flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>

        </flux:header>

        {{-- Page content --}}
        {{ $slot }}

        @fluxScripts
    </body>
</html>
