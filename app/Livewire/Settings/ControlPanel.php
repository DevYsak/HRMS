<?php

namespace App\Livewire\Settings;

use App\Services\ModuleFeatureService;
use Livewire\Component;

/**
 * Admin Control Panel (Phase 2 — Feature 9): a single hub that links every
 * settings / admin area. Cards point at existing routes; each destination still
 * enforces its own middleware, and missing routes are hidden automatically.
 */
class ControlPanel extends Component
{
    public function mount(): void
    {
        $this->authorize('manage-settings');
    }

    /**
     * @return array<int, array{title:string, icon:string, items:array<int, array{label:string, description:string, icon:string, route:string}>}>
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
                ],
            ],
            [
                'title' => 'People & Access',
                'icon' => 'users',
                'items' => [
                    ['label' => 'Roles & Permissions', 'description' => 'Who can do what', 'icon' => 'shield-check', 'route' => 'settings.roles'],
                    ['label' => 'Sidebar Menu', 'description' => 'Employee menu visibility & order', 'icon' => 'bars-3', 'route' => 'settings.menu'],
                    ['label' => 'Import Employees', 'description' => 'Bulk create / update', 'icon' => 'arrow-up-tray', 'route' => 'employees.import'],
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
                ],
            ],
            [
                'title' => 'Notifications & Governance',
                'icon' => 'megaphone',
                'items' => [
                    ['label' => 'Notifications & Email', 'description' => 'Control every email', 'icon' => 'envelope', 'route' => 'settings.notifications'],
                    ['label' => 'Audit Log', 'description' => 'Every change, who & when', 'icon' => 'document-chart-bar', 'route' => 'settings.audit-log'],
                    ['label' => 'AI Assistant', 'description' => 'Provider & access', 'icon' => 'cpu-chip', 'route' => 'settings.ai'],
                    ['label' => 'Modules', 'description' => 'Switch Payroll & Payslips on or off', 'icon' => 'squares-plus', 'route' => 'settings.modules'],
                ],
            ],
        ];
    }

    public function render()
    {
        // A switched-off module's settings are not offered (its pages refuse).
        $payrollOn = app(ModuleFeatureService::class)->payrollEnabled();
        $groups = collect($this->groups())->map(function (array $group) use ($payrollOn) {
            $group['items'] = array_values(array_filter($group['items'],
                fn (array $item) => $payrollOn || ! in_array($item['route'], ['settings.salary-cycles', 'settings.payroll-approval-policy'], true)));

            return $group;
        })->all();

        return view('livewire.settings.control-panel', ['groups' => $groups])
            ->layout('layouts.app', ['title' => 'Control Panel']);
    }
}
