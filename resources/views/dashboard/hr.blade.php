{{--
    HR operations dashboard ("/" for HR and Super Admin, and /dashboard/hr-admin).
    Every figure comes from App\Services\Dashboards\OrganisationOverview over
    the viewer's reach and appears once. Links show only when the viewer can
    open them.
--}}
@php
    $routeAccess = app(\App\Services\Help\RouteAccess::class);
    $me = auth()->user();
    $link = fn (string $route) => $routeAccess->allows($me, $route) ? route($route) : null;
@endphp

<flux:main>
    <div class="mx-auto w-full max-w-[1400px] space-y-5">
        <x-pulse.dashboard-header :title="$heading ?? 'HR operations'" :subtitle="$scopeLabel">
            <x-slot:actions>
                @if($link('employees.create'))
                    <flux:button size="sm" variant="primary" icon="user-plus" :href="$link('employees.create')" wire:navigate>Add employee</flux:button>
                @endif
            </x-slot:actions>
        </x-pulse.dashboard-header>

        {{-- Today --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-pulse.kpi-card label="Working headcount" :value="$people['headcount']" icon="users" :href="$link('employees.index')"
                :sub="$people['new_this_month'].' joined this month'" />
            <x-pulse.kpi-card label="Present today" :value="$attendance['present']" icon="check-circle" accent="emerald" :href="$link('attendance.employees')"
                :sub="$attendance['weekly_off'] ? 'Weekly off' : $attendance['late'].' late'" />
            <x-pulse.kpi-card label="Missing checkout" :value="$attendance['missing_checkout']" icon="exclamation-triangle" accent="amber" :href="$link('attendance.command-center')"
                sub="Past shift end + 1 h" />
            <x-pulse.kpi-card label="Not checked in" :value="$attendance['not_in']" icon="user-minus" accent="rose" :href="$link('attendance.employees')"
                :sub="$attendance['on_leave'].' on approved leave'" />
        </div>

        <div class="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <x-pulse.card title="Waiting for a decision" icon="inbox-stack" flush>
                @include('dashboard.partials.count-list', ['rows' => [
                    ['label' => 'Leave awaiting HR', 'count' => $approvals['leave_hr'], 'href' => $link('time-off.employees')],
                    ['label' => 'Leave with managers', 'count' => $approvals['leave_manager'], 'href' => $link('time-off.employees')],
                    ['label' => 'Attendance regularisations', 'count' => $approvals['regularisations'], 'href' => $link('attendance.command-center')],
                    ['label' => 'Overtime requests', 'count' => $approvals['overtime'], 'href' => $link('overtime.manage')],
                    ['label' => 'WFH requests', 'count' => $approvals['wfh'], 'href' => $link('wfh.manage')],
                    ['label' => 'Leave encashments', 'count' => $approvals['encashments'], 'href' => $link('time-off.encashments')],
                    ['label' => 'Escalated leave', 'count' => $approvals['escalations'], 'href' => $link('notifications.index'), 'tone' => 'rose'],
                ], 'empty' => 'Nothing is waiting for a decision.'])
            </x-pulse.card>

            <x-pulse.card title="HR alerts" icon="bell-alert" flush>
                @include('dashboard.partials.count-list', ['rows' => [
                    ['label' => 'Probation reviews due in 30 days', 'count' => $people['probation_due'], 'href' => $link('employees.index')],
                    ['label' => 'Documents expiring in 30 days', 'count' => $issues['expiring_documents'], 'href' => $link('documents.index')],
                    ['label' => 'Policies not yet acknowledged', 'count' => $issues['pending_acknowledgements'], 'href' => $link('documents.index')],
                    ['label' => 'Open onboarding tasks', 'count' => $issues['onboarding_tasks'], 'href' => $link('employees.onboarding-manager')],
                    ['label' => 'Leavers & open clearances', 'count' => $people['leaving'] + $issues['offboarding_tasks'], 'href' => $link('employees.offboarding-manager')],
                    ['label' => 'Active warning letters', 'count' => $issues['active_warnings'], 'href' => $link('performance.warnings.manage')],
                    ['label' => 'Active improvement plans', 'count' => $issues['active_pips'], 'href' => $link('performance.pip.manage')],
                ], 'empty' => 'No HR alerts.'])
            </x-pulse.card>
        </div>

        @isset($companyExtras)
            @include('dashboard.partials.company-extras', $companyExtras)
        @endisset
    </div>
</flux:main>
