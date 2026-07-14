<?php

namespace App\Livewire\Overtime;

use App\Models\Employee;
use App\Services\NexflowApiService;
use App\Services\OvertimeService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Nexflow overtime panel — pulls an employee's OT from the Nexflow NexBridge
 * API (GET /employees/{email}/ot-details), shows the summary, per-day records
 * with the L1/L2 approval trail and sessions, and lets HR import approved OT
 * into HRMS overtime records so it flows into payroll.
 */
class NexflowOtPanel extends Component
{
    public string $search = '';

    public ?int $employeeId = null;

    public string $from = '';

    public string $to = '';

    /** Status filter sent to Nexflow: '' (all) | approved | pending | rejected. */
    public string $status = '';

    /** Parsed Nexflow payload for the current selection, or null. */
    public ?array $data = null;

    public bool $loading = false;

    public ?string $error = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->canApproveOt(), 403);
        $this->from = now()->startOfMonth()->toDateString();
        $this->to = now()->toDateString();
    }

    public function selectEmployee(int $id): void
    {
        $this->employeeId = $id;
        $this->data = null;
        $this->error = null;
        $this->fetch();
    }

    public function fetch(?NexflowApiService $nexflow = null): void
    {
        abort_unless(Auth::user()->canApproveOt(), 403);

        $nexflow ??= app(NexflowApiService::class);
        $this->error = null;
        $this->data = null;

        $employee = $this->selectedEmployee();
        if (! $employee) {
            $this->error = 'Pick an employee to load their Nexflow overtime.';

            return;
        }
        if (! $employee->user?->email) {
            $this->error = 'This employee has no email on file — Nexflow is keyed by work email.';

            return;
        }

        $this->loading = true;

        $result = $nexflow->getOtDetails($employee->user->email, $this->from, $this->to, $this->status ?: null);

        $this->loading = false;

        if ($result === null) {
            $this->error = 'No overtime found in Nexflow for this employee/period — or Nexflow is unreachable. Check the connection in settings.';

            return;
        }

        $this->data = $result;
    }

    public function importRecord(int $recordId, OvertimeService $ot): void
    {
        abort_unless(Auth::user()->canApproveOt(), 403);

        $employee = $this->selectedEmployee();
        $record = collect($this->data['ot_records'] ?? [])->firstWhere('id', $recordId);

        if (! $employee || ! $record) {
            \Flux::toast('That overtime record is no longer available — refresh and try again.', variant: 'warning');

            return;
        }

        $outcome = $ot->importNexflowOtRecord($employee, $record);

        match ($outcome['status']) {
            'imported' => \Flux::toast('Overtime imported to payroll.'),
            'skipped' => \Flux::toast('Skipped — an overtime record already exists for that day.', variant: 'warning'),
            default => \Flux::toast('Only fully approved (L1 + L2) overtime is payable.', variant: 'warning'),
        };

        $this->fetch();
    }

    public function importAllApproved(OvertimeService $ot): void
    {
        abort_unless(Auth::user()->canApproveOt(), 403);

        $employee = $this->selectedEmployee();
        if (! $employee) {
            return;
        }

        $imported = 0;
        $skipped = 0;
        foreach ($this->data['ot_records'] ?? [] as $record) {
            $outcome = $ot->importNexflowOtRecord($employee, $record);
            $outcome['status'] === 'imported' ? $imported++ : ($outcome['status'] === 'skipped' ? $skipped++ : null);
        }

        \Flux::toast("Imported {$imported} approved overtime record(s)".($skipped ? ", skipped {$skipped} already logged." : '.'));
        $this->fetch();
    }

    protected function selectedEmployee(): ?Employee
    {
        return $this->employeeId
            ? Employee::with('user')->find($this->employeeId)
            : null;
    }

    public function render()
    {
        $employees = Employee::query()
            ->where('status', 'active')
            ->whereHas('user')
            ->with('user')
            ->when($this->search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('name', 'like', '%'.$this->search.'%')->orWhere('email', 'like', '%'.$this->search.'%')))
            ->orderBy('id')
            ->limit(50)
            ->get();

        return view('livewire.overtime.nexflow-ot-panel', [
            'employees' => $employees,
            'selected' => $this->selectedEmployee(),
        ])->layout('layouts.app', ['title' => 'Nexflow Overtime']);
    }
}
