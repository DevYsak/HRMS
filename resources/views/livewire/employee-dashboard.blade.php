<flux:main class="min-h-screen bg-[#FAFAF9] p-4 sm:p-5 lg:p-6 dark:bg-[#0B1220]">
    {{-- Employee self-service dashboard. Every card is a component under
         components/employee/dashboard, fed by EmployeeDashboardService —
         no queries or business rules live in this view. --}}
    <div class="mx-auto w-full max-w-[1500px] space-y-4">

        @if(! $employee)
            <x-employee.dashboard.welcome-card :profile="$profile" />

            <flux:callout icon="exclamation-triangle" variant="warning">
                <flux:callout.heading>Your employee profile isn't linked yet</flux:callout.heading>
                <flux:callout.text>
                    Attendance, leave and payroll appear here once HR connects your account to an employee record.
                    Please contact HR.
                </flux:callout.text>
            </flux:callout>
        @else
            {{-- A module switched off in Settings → Modules leaves no card
                 behind: the payroll card exists only when payroll is on. --}}
            @php
                $hasPayroll = $payroll !== null;
                $showCompletion = ($profileCompletion['percent'] ?? 100) < 100;
            @endphp

            {{-- 1 · Greeting, today's status / clock-in, shift, next holiday --}}
            <x-employee.dashboard.welcome-card :profile="$profile" :next-holiday="$nextPublicHoliday">
                <x-slot:aside>
                    <x-employee.dashboard.status-card :today="$today" />
                </x-slot:aside>
            </x-employee.dashboard.welcome-card>

            {{-- 2 · Things waiting on the employee (hidden when there are none) --}}
            <x-employee.dashboard.alert-strip :alerts="$alerts" />

            {{-- 3 · The compact KPI row --}}
            <x-employee.dashboard.kpi-cards :kpis="$kpis" />

            {{-- 4 · Cards: a main column (8/12) and a side rail (4/12) on
                 desktop, each a plain top-aligned stack so a card is only as
                 tall as its content. Below xl both wrappers are `contents`, the
                 cards join one grid and `order` keeps the reading order. --}}
            <div class="grid gap-4 transition-opacity max-xl:grid-flow-row-dense md:grid-cols-2 xl:grid-cols-12 xl:items-start"
                 wire:loading.delay.class="animate-pulse opacity-70">
                <div class="contents xl:col-span-8 xl:flex xl:min-w-0 xl:flex-col xl:gap-4">
                    <x-employee.dashboard.attendance-overview :attendance="$attendance" class="md:col-span-2 max-xl:order-1" />

                    <div class="contents xl:grid xl:grid-cols-2 xl:items-start xl:gap-4">
                        <x-employee.dashboard.today-timeline :today="$today" class="max-xl:order-3" />
                        @if($hasPayroll)
                            <x-employee.dashboard.payroll-summary :payroll="$payroll" class="max-xl:order-4" />
                        @else
                            <x-employee.dashboard.performance-summary :performance="$performance" class="max-xl:order-5" />
                        @endif
                    </div>

                    <div class="contents xl:grid xl:grid-cols-2 xl:items-start xl:gap-4">
                        <x-employee.dashboard.announcements :announcements="$announcements" class="max-xl:order-7" />
                        <x-employee.dashboard.recent-activity :activity="$activity" class="max-xl:order-9" />
                    </div>
                </div>

                <div class="contents xl:col-span-4 xl:flex xl:min-w-0 xl:flex-col xl:gap-4">
                    <x-employee.dashboard.leave-summary :leave="$leave" class="max-xl:order-2" />
                    @if($showCompletion)
                        <x-employee.dashboard.profile-completion :completion="$profileCompletion" class="max-xl:order-2" />
                    @endif
                    <x-employee.dashboard.upcoming-holidays :holidays="$upcomingHolidays" class="max-xl:order-6" />
                    @if($hasPayroll)
                        <x-employee.dashboard.performance-summary :performance="$performance" class="max-xl:order-5" />
                    @endif
                    <x-employee.dashboard.quick-actions :actions="$quickActions" class="max-xl:order-8" />
                    <x-employee.dashboard.documents :documents="$documents" class="max-xl:order-10" />
                    <x-employee.dashboard.team-card :team="$team" class="max-xl:order-11" />
                </div>
            </div>
        @endif
    </div>
</flux:main>
