<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use App\Services\NexflowApiService;
use Livewire\Component;

/**
 * NexflowActivity — Livewire component
 *
 * Lazy-loads Nexflow clock data for an employee when the "Nexflow" tab
 * is clicked on the Employee Edit page. Shows daily work time and break
 * time pulled live from the NexBridge API.
 *
 * Usage in blade: <livewire:employees.nexflow-activity :employee="$employee" />
 */
class NexflowActivity extends Component
{
    public Employee $employee;

    /** Date range controls */
    public string $from = '';

    public string $to = '';

    /** Loaded API response */
    public ?array $clockData = null;

    /** UI state */
    public bool $loading = false;

    public bool $loaded = false;

    public ?string $errorMsg = null;

    public function mount(Employee $employee): void
    {
        $this->employee = $employee;
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    /**
     * Fetch data from Nexflow via NexflowApiService.
     * Called on first load and on date range change.
     */
    public function loadData(): void
    {
        $this->loading = true;
        $this->errorMsg = null;
        $this->clockData = null;

        $email = $this->employee->user->email ?? null;

        if (! $email) {
            $this->errorMsg = 'This employee has no email address linked — cannot look up Nexflow data.';
            $this->loading = false;

            return;
        }

        /** @var NexflowApiService $service */
        $service = app(NexflowApiService::class);

        // Test connection config first
        if (empty(config('nexbridge.nexflow_url')) || empty(config('nexbridge.secret'))) {
            $this->errorMsg = 'NexBridge is not configured on this server. Please set NEXBRIDGE_NEXFLOW_URL and NEXBRIDGE_SECRET in .env.';
            $this->loading = false;

            return;
        }

        $data = $service->getClockSummary($email, $this->from, $this->to);

        if ($data === null) {
            $this->errorMsg = 'Could not fetch data from Nexflow. The employee may not exist there, or Nexflow is temporarily unreachable.';
        } else {
            $this->clockData = $data;
        }

        $this->loading = false;
        $this->loaded = true;
    }

    /**
     * React to date range changes — reload data automatically.
     */
    public function updatedFrom(): void
    {
        if ($this->loaded) {
            $this->loadData();
        }
    }

    public function updatedTo(): void
    {
        if ($this->loaded) {
            $this->loadData();
        }
    }

    public function render()
    {
        return view('livewire.employees.nexflow-activity');
    }
}
