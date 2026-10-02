<?php

namespace App\Livewire\TimeOff;

use App\Models\Department;
use App\Models\LeaveType;
use App\Models\LeaveYear;
use App\Services\Leave\LeaveReconciliationService;
use App\Services\Leave\LeaveYearResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR → Leave Management → Reconciliation (Phase 2C/2D).
 *
 * The scan is a dry run and writes nothing. HR then reconciles the selected
 * rows or every SAFE_AUTO_FIX row; NEEDS_HR_REVIEW and BLOCKED rows are
 * never auto-fixed.
 */
class LeaveReconciliation extends Component
{
    public ?int $leaveYearId = null;

    public ?int $leaveTypeId = null;

    public ?int $departmentId = null;

    public string $classification = '';

    /** @var array<int, string> "employeeId:leaveTypeId" */
    public array $selected = [];

    /** Nothing is scanned until HR asks — the scan touches every employee. */
    public bool $scanned = false;

    public function mount(LeaveYearResolver $years): void
    {
        $this->authorize('reconcile_leave');

        $this->leaveYearId = $years->current()->id;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['leaveYearId', 'leaveTypeId', 'departmentId'], true)) {
            $this->selected = [];
            unset($this->rows);
        }
    }

    #[Computed]
    public function year(): LeaveYear
    {
        return LeaveYear::findOrFail($this->leaveYearId);
    }

    /** @return Collection<int, array<string, mixed>> */
    #[Computed]
    public function rows(): Collection
    {
        if (! $this->scanned) {
            return collect();
        }

        return app(LeaveReconciliationService::class)->scan($this->year, [
            'leave_type_id' => $this->leaveTypeId,
            'department_id' => $this->departmentId,
        ]);
    }

    public function scan(): void
    {
        $this->authorize('reconcile_leave');

        $this->scanned = true;
        unset($this->rows);
    }

    public function reconcileSelected(): void
    {
        $this->authorize('reconcile_leave');

        if ($this->selected === []) {
            session()->flash('error', 'Select at least one row.');

            return;
        }

        $this->report(app(LeaveReconciliationService::class)->reconcile($this->year, Auth::user(), $this->selected));
    }

    public function reconcileAllSafe(): void
    {
        $this->authorize('reconcile_leave');

        $this->report(app(LeaveReconciliationService::class)->reconcile($this->year, Auth::user()));
    }

    public function export(): StreamedResponse
    {
        $this->authorize('export_leave');

        $rows = $this->rows;

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee', 'Code', 'Policy', 'Leave Type', 'Expected Base', 'Actual Base', 'Carry Forward', 'Add-On', 'Accrual', 'Used', 'Pending', 'Expired', 'Available',
                'Missing', 'Duplicate', 'Incorrect Accrual', 'Carry-only Row', 'Classification', 'Issues', 'Recommended Action']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['employee'], $r['employee_code'], $r['policy'], $r['leave_type'], $r['expected_base'], $r['actual_base'], $r['carry_forward'],
                    $r['add_on'], $r['accrual'], $r['used'], $r['pending'], $r['expired'], $r['available'],
                    $r['missing'] ? 'yes' : 'no', $r['duplicate'] ? 'yes' : 'no', $r['incorrect_accrual'] ? 'yes' : 'no', $r['carry_only'] ? 'yes' : 'no',
                    $r['classification'], implode(' | ', $r['issues']), $r['recommended_action']]);
            }
            fclose($out);
        }, 'leave-reconciliation-'.str_replace('/', '-', $this->year->label).'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @param  array<string, mixed>  $result */
    private function report(array $result): void
    {
        session()->flash($result['failed'] > 0 ? 'error' : 'success',
            "Fixed {$result['fixed']} row(s); {$result['skipped']} left for HR review or blocked; {$result['failed']} failed.".
            ($result['errors'] ? ' '.implode('; ', array_slice($result['errors'], 0, 3)) : ''));

        $this->selected = [];
        unset($this->rows);
    }

    public function render()
    {
        $rows = $this->rows;

        return view('livewire.time-off.leave-reconciliation', [
            'visible' => $this->classification ? $rows->where('classification', $this->classification)->values() : $rows->where('classification', '!=', 'OK')->values(),
            'counts' => $rows->countBy('classification')->all(),
            'leaveYears' => LeaveYear::orderByDesc('starts_on')->get(),
            'leaveTypes' => LeaveType::whereNull('deleted_at')->orderBy('name')->get(),
            'departments' => Department::orderBy('name')->get(),
        ]);
    }
}
