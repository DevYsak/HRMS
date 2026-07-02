<?php

namespace App\Livewire\Settings;

use App\Models\Employee;
use App\Services\DataPurgeService;
use Livewire\Component;

/**
 * Super-Admin-only data cleanup: bulk-clear operational data by domain, and
 * permanently delete a single employee everywhere. Every action is guarded by a
 * type-to-confirm prompt in the view and audited in the log.
 */
class DataManagement extends Component
{
    public string $employeeSearch = '';

    /** @var array<int, int> selected employee ids */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);
    }

    public function deleteSelected(DataPurgeService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $result = $service->bulkDeleteEmployees($this->selected, auth()->user());
        $this->selected = [];

        \Flux::toast("Deleted {$result['deleted']} employee(s)".($result['skipped'] ? ", skipped {$result['skipped']} protected" : '').'.', variant: 'success');
    }

    public function deleteAllEmployees(DataPurgeService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $ids = $service->deletableEmployees(auth()->user())->pluck('id');
        $result = $service->bulkDeleteEmployees($ids, auth()->user());
        $this->selected = [];

        \Flux::toast("Deleted {$result['deleted']} employee(s)".($result['skipped'] ? ", skipped {$result['skipped']} protected" : '').'.', variant: 'success');
    }

    public function purge(string $domain, DataPurgeService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        try {
            $count = $service->purge($domain, auth()->user());
            \Flux::toast("Cleared {$count} record(s) from ".(DataPurgeService::DOMAINS[$domain]['label'] ?? $domain).'.', variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function deleteEmployee(int $employeeId, DataPurgeService $service): void
    {
        abort_unless(auth()->user()?->isSuperAdmin(), 403);

        $employee = Employee::with('user')->findOrFail($employeeId);

        try {
            $name = $employee->user?->name ?? "Employee #{$employee->id}";
            $service->deleteEmployee($employee, auth()->user());
            \Flux::toast("{$name} permanently deleted.", variant: 'success');
        } catch (\Throwable $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }
    }

    public function render()
    {
        $employees = $this->employeeSearch !== ''
            ? Employee::with('user')
                ->whereHas('user', function ($q): void {
                    $q->where('name', 'like', '%'.$this->employeeSearch.'%')
                        ->orWhere('email', 'like', '%'.$this->employeeSearch.'%');
                })
                ->limit(10)->get()
            : collect();

        return view('livewire.settings.data-management', [
            'domains' => app(DataPurgeService::class)->counts(),
            'employees' => $employees,
            'deletableCount' => app(DataPurgeService::class)->deletableEmployees(auth()->user())->count(),
        ])->layout('layouts.app', ['title' => 'Data Management']);
    }
}
