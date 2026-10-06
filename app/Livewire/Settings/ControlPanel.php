<?php

namespace App\Livewire\Settings;

use App\Services\ModuleFeatureService;
use Livewire\Component;

/**
 * Admin Control Panel (Phase 2 — Feature 9): a single hub that links every
 * settings / admin area. Cards point at existing routes; each destination still
 * enforces its own middleware, and missing routes are hidden automatically.
 *
 * Dynamic: a card naming a 'permission' is shown only to holders of it (Roles
 * & Permissions in the Role Manager decides who that is), so HR sees exactly
 * the controls their role grants — no card leads to a 403.
 */
class ControlPanel extends Component
{
    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    /**
     * @return array<int, array{title:string, icon:string, items:array<int, array{label:string, description:string, icon:string, route:string, permission?:string}>}>
     */
    private function groups(): array
    {
        return [
            [
                'title' => 'Company & Organisation',
                'icon' => 'building-office',
                'items' => [
                    ['label' => 'Company', 'description' => 'Name, offices, departments, branding', 'icon' => 'building-office', 'route' => 'settings.general'],
                    ['label' => 'Departments', 'description' => 'Department structure', 'icon' => 'user-group', 'route' => 'settings.departments'],
                    ['label' => 'Job Titles', 'description' => 'Designations', 'icon' => 'briefcase', 'route' => 'settings.job-titles'],
                    ['label' => 'Employment Types', 'description' => 'Contract types & probation', 'icon' => 'identification', 'route' => 'settings.employment-types'],
                    ['label' => 'Work Modes', 'description' => 'On-site / hybrid / remote', 'icon' => 'home-modern', 'route' => 'settings.work-modes'],
                    ['label' => 'Salary Cycles', 'description' => 'Payroll cycles', 'icon' => 'calendar-days', 'route' => 'settings.salary-cycles'],
                    ['label' => 'Payroll Approval Policy', 'description' => 'Who signs off a payroll run', 'icon' => 'check-badge', 'route' => 'settings.payroll-approval-policy'],
                ],
            ],
            [
                'title' => 'People & Access',
                'icon' => 'users',
                'items' => [
                    ['label' => 'Roles & Permissions', 'description' => 'Who can do what', 'icon' => 'shield-check', 'route' => 'settings.roles', 'permission' => 'manage_roles'],
                    ['label' => 'Sidebar Menu', 'description' => 'Employee menu visibility & order', 'icon' => 'bars-3', 'route' => 'settings.menu'],
                    ['label' => 'Import Employees', 'description' => 'Bulk create / update', 'icon' => 'arrow-up-tray', 'route' => 'employees.import', 'permission' => 'manage_employees'],
                    ['label' => 'Onboarding Templates', 'description' => 'New-hire checklists', 'icon' => 'clipboard-document-check', 'route' => 'settings.onboarding-templates'],
                ],
            ],
            [
                'title' => 'Time & Leave',
                'icon' => 'clock',
                'items' => [
                    ['label' => 'Leave Settings', 'description' => 'Leave types & rules', 'icon' => 'calendar-days', 'route' => 'time-off.settings'],
                    ['label' => 'Leave Policies', 'description' => 'Conditional default allocations', 'icon' => 'adjustments-horizontal', 'route' => 'time-off.leave-policies'],
                    ['label' => 'Bulk Leave', 'description' => 'Assign balances in bulk', 'icon' => 'user-group', 'route' => 'time-off.bulk-assign'],
                    ['label' => 'Attendance Settings', 'description' => 'Shifts, grace, biometric', 'icon' => 'clock', 'route' => 'attendance.settings'],
                    ['label' => 'Holidays', 'description' => 'Holiday calendars & lists', 'icon' => 'sun', 'route' => 'settings.holidays'],
                    ['label' => 'Holiday Pay', 'description' => 'Pay for work on holidays', 'icon' => 'banknotes', 'route' => 'settings.holiday-pay'],
                ],
            ],
            [
                'title' => 'Notifications & Governance',
                'icon' => 'megaphone',
                'items' => [
                    ['label' => 'Notifications & Email', 'description' => 'Control every email', 'icon' => 'envelope', 'route' => 'settings.notifications'],
                    ['label' => 'Audit Log', 'description' => 'Every change, who & when', 'icon' => 'document-chart-bar', 'route' => 'settings.audit-log'],
                    ['label' => 'AI Assistant', 'description' => 'Provider & access', 'icon' => 'cpu-chip', 'route' => 'settings.ai', 'permission' => 'manage_ai_settings'],
                    ['label' => 'Modules', 'description' => 'Switch Payroll & Payslips on or off', 'icon' => 'squares-plus', 'route' => 'settings.modules'],
                    ['label' => 'Data Management', 'description' => 'Clear test data, purge employees', 'icon' => 'trash', 'route' => 'settings.data-management', 'permission' => 'data_purge'],
                ],
            ],
        ];
    }

    public function render()
    {
        // A switched-off module's settings are not offered (its pages refuse).
        $payrollOn = app(ModuleFeatureService::class)->payrollEnabled();
        $user = auth()->user();
        $groups = collect($this->groups())->map(function (array $group) use ($payrollOn, $user) {
            $group['items'] = array_values(array_filter($group['items'],
                fn (array $item) => ($payrollOn || ! in_array($item['route'], ['settings.salary-cycles', 'settings.payroll-approval-policy'], true))
                    && (! isset($item['permission']) || $user->hasPermission($item['permission']))));

            return $group;
        })->all();

        return view('livewire.settings.control-panel', ['groups' => $groups])
            ->layout('layouts.app', ['title' => 'Control Panel']);
    }
}
