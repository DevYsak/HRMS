<flux:main class="min-h-screen bg-[#FAFAF9] p-4 sm:p-6 lg:p-8 dark:bg-[#0B1220]">
    {{-- Employee self-service dashboard. Every card is a component under
         components/employee/dashboard, fed by EmployeeDashboardService —
         no queries or business rules live in this view. --}}
    <div class="mx-auto w-full max-w-[1600px] space-y-5">

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
            @php $hasPayroll = $payroll !== null; @endphp

            {{-- 1 · Welcome + today's status --}}
            <x-employee.dashboard.welcome-card :profile="$profile" :next-holiday="$nextPublicHoliday">
                <x-slot:aside>
                    <x-employee.dashboard.status-card :today="$today" />
                </x-slot:aside>
            </x-employee.dashboard.welcome-card>

            {{-- 2 · Things waiting on the employee (hidden when there are none) --}}
            <x-employee.dashboard.alert-strip :alerts="$alerts" />

            {{-- 3 · Key figures --}}
            <x-employee.dashboard.kpi-cards :kpis="$kpis" />

            {{-- 4 · Cards. On desktop: a main column and a side rail, each a
                 plain stack, so a card is only as tall as its content — in one
                 shared grid row a long leave list stretched the attendance card
                 into an empty box. Below xl both wrappers are `contents`, the
                 cards join one grid, and `order` restores the reading order
                 (attendance, leave, timeline, payroll, performance, …). --}}
            <div class="grid gap-5 max-xl:grid-flow-row-dense md:grid-cols-2 xl:grid-cols-3 xl:items-start">
                <div class="contents xl:col-span-2 xl:flex xl:min-w-0 xl:flex-col xl:gap-5">
                    <x-employee.dashboard.attendance-overview :attendance="$attendance" class="md:col-span-2 max-xl:order-1" />

                    <div class="contents xl:grid xl:grid-cols-2 xl:gap-5">
                        <x-employee.dashboard.today-timeline :today="$today" class="max-xl:order-3" />
                        @if($hasPayroll)
                            <x-employee.dashboard.payroll-summary :payroll="$payroll" class="max-xl:order-4" />
                        @else
                            <x-employee.dashboard.performance-summary :performance="$performance" class="max-xl:order-5" />
                        @endif
                    </div>

                    <x-employee.dashboard.announcements :announcements="$announcements" class="md:col-span-2 max-xl:order-6" />
                    <x-employee.dashboard.recent-activity :activity="$activity" class="max-xl:order-8" />
                </div>

                <div class="contents xl:flex xl:min-w-0 xl:flex-col xl:gap-5">
                    <x-employee.dashboard.leave-summary :leave="$leave" class="max-xl:order-2" />
                    @if($hasPayroll)
                        <x-employee.dashboard.performance-summary :performance="$performance" class="max-xl:order-5" />
                    @endif
                    <x-employee.dashboard.quick-actions :actions="$quickActions" class="max-xl:order-7" />
                    <x-employee.dashboard.documents :documents="$documents" class="max-xl:order-9" />
                    <x-employee.dashboard.team-card :team="$team" class="max-xl:order-10" />
                </div>
            </div>
        @endif
    </div>
</flux:main>
