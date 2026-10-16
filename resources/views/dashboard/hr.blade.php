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

        {{-- People Operations Today: attendance, approvals, reviews, department health and data
             quality, all from the canonical services over one department selector. --}}
        <livewire:dashboard.people-operations />

        @php
            $typeLinks = ['leave' => 'time-off.team', 'regularisation' => 'attendance.command-center', 'overtime' => 'overtime.manage', 'wfh' => 'wfh.manage', 'holiday' => 'time-off.team'];
            $typeLabels = ['leave' => 'Leave requests', 'regularisation' => 'Attendance regularisations', 'overtime' => 'Overtime requests', 'wfh' => 'WFH requests', 'holiday' => 'Holiday work requests'];
        @endphp
        <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
            {{-- Same queue as the Approvals badge and Command Center (ApprovalQueueService). --}}
            <x-pulse.card title="Waiting for your decision" subtitle="Requests you can approve now" icon="check-badge" flush data-my-approvals>
                @include('dashboard.partials.count-list', ['rows' => collect($myApprovals)->map(fn ($count, $type) => [
                    'label' => $typeLabels[$type] ?? \Illuminate\Support\Str::headline($type), 'count' => $count, 'href' => $link($typeLinks[$type] ?? 'attendance.command-center'),
                ])->values()->all(), 'empty' => 'Nothing is waiting for your decision.'])
            </x-pulse.card>

            {{-- Everything open in scope, whoever decides it (managers included). --}}
            <x-pulse.card title="Open requests in your scope" subtitle="Pending with anyone — managers, HR or Finance" icon="inbox-stack" flush data-open-in-scope>
                @include('dashboard.partials.count-list', ['rows' => [
                    ['label' => 'Leave awaiting HR', 'count' => $approvals['leave_hr'], 'href' => $link('time-off.employees')],
                    ['label' => 'Leave with managers', 'count' => $approvals['leave_manager'], 'href' => $link('time-off.employees')],
                    ['label' => 'Attendance regularisations', 'count' => $approvals['regularisations'], 'href' => $link('attendance.command-center')],
                    ['label' => 'Overtime requests', 'count' => $approvals['overtime'], 'href' => $link('overtime.manage')],
                    ['label' => 'WFH requests', 'count' => $approvals['wfh'], 'href' => $link('wfh.manage')],
                    ['label' => 'Leave encashments', 'count' => $approvals['encashments'], 'href' => $link('time-off.encashments')],
                    ['label' => 'Escalated leave', 'count' => $approvals['escalations'], 'href' => $link('notifications.index'), 'tone' => 'rose'],
                    ['label' => 'Needs more info — back with the employee', 'count' => $moreInfo['leave'], 'href' => $link('time-off.employees'), 'tone' => 'amber'],
                ], 'empty' => 'No open requests in your scope.'])
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

        @if($me->hasPermission('view_qa_audit'))
            @include('dashboard.partials.qa-readiness')
        @endif

        @isset($companyExtras)
            @include('dashboard.partials.company-extras', $companyExtras)
        @endisset
    </div>
</flux:main>
