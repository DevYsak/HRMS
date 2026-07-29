<?php

namespace App\Livewire\Payroll;

use App\Models\Department;
use App\Models\Office;
use App\Models\Payroll;
use App\Models\Payslip;
use App\Services\PayrollService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class Process extends Component
{
    use WithPagination;

    public string $month;

    public int $year;

    public string $cycle = 'cycle_a';

    // Filters
    public string $search = '';

    public string $filterDepartment = '';

    public string $filterLocation = '';

    public string $filterStatus = '';

    public int $perPage = 10;

    public ?Payroll $currentPayroll = null;

    /** Departments list for filter dropdown. */
    public $departments;

    /** Offices list for filter dropdown. */
    public $offices;

    /** @var array<int, int> Payslip ids ticked for bulk actions. */
    public array $selected = [];

    /** Payslip currently open in the edit modal, or null. */
    public ?int $editingId = null;

    /** @var array<int, array{name: string, amount: float, type: string}> */
    public array $editItems = [];

    public function mount(): void
    {
        $this->month = Carbon::now()->format('F');
        $this->year = Carbon::now()->year;
        $this->departments = Department::orderBy('name')->get();
        $this->offices = Office::orderBy('name')->get();
        $this->loadCurrentPayroll();
    }

    public function loadCurrentPayroll(): void
    {
        $this->currentPayroll = Payroll::where('month', $this->month)
            ->where('year', $this->year)
            ->where('cycle', $this->cycle)
            ->first();
    }

    /** Reactive: reload payroll when month/year/cycle changes. */
    public function updatedMonth(): void
    {
        $this->resetPage();
        $this->loadCurrentPayroll();
    }

    public function updatedYear(): void
    {
        $this->resetPage();
        $this->loadCurrentPayroll();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDepartment(): void
    {
        $this->resetPage();
    }

    public function updatedFilterLocation(): void
    {
        $this->resetPage();
    }

    public function updatedFilterStatus(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        $this->resetPage();
    }

    /** Paginated + filtered payslips for the current payroll. */
    public function getPayslipsProperty(): LengthAwarePaginator
    {
        if (! $this->currentPayroll) {
            return Payslip::whereRaw('0=1')->paginate($this->perPage)->withPath(route('payroll.process'));
        }

        $query = Payslip::with(['employee.user', 'employee.department', 'employee.office', 'payroll'])
            ->where('payroll_id', $this->currentPayroll->id);

        if ($this->search !== '') {
            $search = $this->search;
            $query->whereHas('employee', function ($q) use ($search) {
                $q->where('employee_id', 'like', "%{$search}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$search}%"));
            });
        }

        if ($this->filterDepartment !== '') {
            $query->whereHas('employee', fn ($q) => $q->where('department_id', $this->filterDepartment));
        }

        if ($this->filterLocation !== '') {
            $query->whereHas('employee', fn ($q) => $q->where('office_id', $this->filterLocation));
        }

        if ($this->filterStatus !== '') {
            // Match on payslip status OR payroll status
            $status = $this->filterStatus;
            if ($status === 'pending_finance') {
                $query->whereHas('payroll', fn ($q) => $q->where('status', 'pending_finance'));
            } else {
                $query->where('status', $status);
            }
        }

        return $query->paginate($this->perPage)->withPath(route('payroll.process'));
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->filterDepartment = '';
        $this->filterLocation = '';
        $this->filterStatus = '';
        $this->resetPage();
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    /** Generate (or re-generate) the payroll draft. */
    public function startProcessing(): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if ($this->currentPayroll && $this->currentPayroll->status === 'finalized') {
            \Flux::toast('Payroll for this period is already finalized.', variant: 'danger');

            return;
        }

        if ($this->currentPayroll && $this->currentPayroll->status === 'pending_finance') {
            \Flux::toast('Payroll is pending finance approval.', variant: 'danger');

            return;
        }

        try {
            app(PayrollService::class)->generateDraft($this->month, $this->year, $this->cycle, Auth::id());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->loadCurrentPayroll();
        \Flux::toast("Payroll draft generated for {$this->month} {$this->year}.");
    }

    /** Submit draft to finance for approval. */
    public function submitForApproval(): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if (! $this->currentPayroll || $this->currentPayroll->status !== 'draft') {
            \Flux::toast('No draft payroll to submit.', variant: 'danger');

            return;
        }

        app(PayrollService::class)->submitForFinanceApproval($this->currentPayroll);
        $this->loadCurrentPayroll();
        \Flux::toast('Payroll submitted for finance approval.');
    }

    /** Lock the current finalized payroll so it can no longer be regenerated. */
    public function lockPayroll(): void
    {
        abort_unless(Auth::user()->canLockPayroll(), 403);

        if (! $this->currentPayroll) {
            return;
        }

        try {
            app(PayrollService::class)->lock($this->currentPayroll, Auth::id());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->loadCurrentPayroll();
        \Flux::toast('Payroll locked.');
    }

    /** Unlock the current payroll, allowing it to be regenerated again. */
    public function unlockPayroll(): void
    {
        abort_unless(Auth::user()->canUnlockPayroll(), 403);

        if (! $this->currentPayroll) {
            return;
        }

        try {
            app(PayrollService::class)->unlock($this->currentPayroll);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->loadCurrentPayroll();
        \Flux::toast('Payroll unlocked.');
    }

    // ── Per-payslip actions ──────────────────────────────────────────────────

    public function regenerateSingle(int $payslipId): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        $payslip = Payslip::with('employee')->findOrFail($payslipId);

        try {
            app(PayrollService::class)->regenerateSinglePayslip($payslip->payroll, $payslip->employee, Auth::id());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->loadCurrentPayroll();
        \Flux::toast('Payslip regenerated for '.($payslip->employee->user?->name ?? 'employee').'.');
    }

    public function deleteSingle(int $payslipId): void
    {
        abort_unless(Auth::user()->canDeletePayslip(), 403);

        $payslip = Payslip::findOrFail($payslipId);

        try {
            app(PayrollService::class)->deletePayslip($payslip);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->selected = array_values(array_diff($this->selected, [$payslipId]));
        $this->loadCurrentPayroll();
        \Flux::toast('Payslip deleted.');
    }

    public function lockSinglePayslip(int $payslipId): void
    {
        abort_unless(Auth::user()->canLockPayroll(), 403);

        try {
            app(PayrollService::class)->lockPayslip(Payslip::findOrFail($payslipId), Auth::id());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Payslip locked.');
    }

    public function unlockSinglePayslip(int $payslipId): void
    {
        abort_unless(Auth::user()->canUnlockPayroll(), 403);

        try {
            app(PayrollService::class)->unlockPayslip(Payslip::findOrFail($payslipId));
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Payslip unlocked.');
    }

    public function emailSingle(int $payslipId): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        try {
            app(PayrollService::class)->emailPayslip(Payslip::with('employee.user')->findOrFail($payslipId));
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Payslip queued for email.');
    }

    // ── Edit modal (draft payslip line items) ────────────────────────────────

    public function openEdit(int $payslipId): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        $payslip = Payslip::with('items')->findOrFail($payslipId);

        $this->editingId = $payslipId;
        $this->editItems = $payslip->items->map(fn ($i) => [
            'name' => $i->name, 'amount' => (float) $i->amount, 'type' => $i->type,
        ])->all();

        $this->modal('edit-payslip')->show();
    }

    public function addEditItem(): void
    {
        $this->editItems[] = ['name' => '', 'amount' => 0, 'type' => 'earning'];
    }

    public function removeEditItem(int $index): void
    {
        unset($this->editItems[$index]);
        $this->editItems = array_values($this->editItems);
    }

    public function saveEdit(): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        $this->validate([
            'editItems' => ['array', 'min:1'],
            'editItems.*.name' => ['required', 'string', 'max:255'],
            'editItems.*.amount' => ['required', 'numeric', 'min:0'],
            'editItems.*.type' => ['required', 'in:earning,deduction,employer_contribution'],
        ]);

        $payslip = Payslip::findOrFail($this->editingId);

        try {
            app(PayrollService::class)->updatePayslipItems($payslip, $this->editItems);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->modal('edit-payslip')->close();
        $this->editingId = null;
        $this->editItems = [];
        $this->loadCurrentPayroll();
        \Flux::toast('Payslip updated.');
    }

    // ── Bulk actions ──────────────────────────────────────────────────────────

    public function selectAllVisible(): void
    {
        $this->selected = $this->payslips->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function clearSelection(): void
    {
        $this->selected = [];
    }

    public function bulkDownload()
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if ($this->selected === []) {
            \Flux::toast('Select at least one payslip to download.', variant: 'danger');

            return null;
        }

        return $this->redirect(route('payroll.payslips.download-bulk', ['ids' => $this->selected]));
    }

    public function bulkEmail(): void
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if ($this->selected === []) {
            \Flux::toast('Select at least one payslip to email.', variant: 'danger');

            return;
        }

        $service = app(PayrollService::class);
        $done = 0;
        $failed = 0;

        foreach (Payslip::with('employee.user')->whereIn('id', $this->selected)->get() as $payslip) {
            try {
                $service->emailPayslip($payslip);
                $done++;
            } catch (\Throwable) {
                $failed++;
            }
        }

        \Flux::toast(
            "Bulk email: {$done} queued".($failed ? ", {$failed} failed" : '.'),
            variant: $failed ? 'warning' : 'success',
        );

        $this->selected = [];
    }

    // ── Export ────────────────────────────────────────────────────────────────

    /** Payroll Register — every payslip in the current run, one row each. */
    public function exportExcel()
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        if (! $this->currentPayroll) {
            \Flux::toast('No payroll to export.', variant: 'danger');

            return null;
        }

        $rows = Payslip::with(['employee.user', 'employee.department'])
            ->where('payroll_id', $this->currentPayroll->id)
            ->get()
            ->map(fn (Payslip $p) => [
                $p->employee->employee_id ?? $p->employee_id,
                $p->employee->user?->name ?? '—',
                $p->employee->department?->name ?? '—',
                number_format((float) $p->gross_salary, 2, '.', ''),
                number_format((float) $p->total_deductions, 2, '.', ''),
                number_format((float) $p->net_salary, 2, '.', ''),
                $p->status,
            ]);

        $filename = 'payroll-register-'.$this->month.'-'.$this->year.'.csv';

        return response()->streamDownload(function () use ($rows) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Employee ID', 'Employee Name', 'Department', 'Gross', 'Deductions', 'Net Pay', 'Status']);
            foreach ($rows as $row) {
                fputcsv($handle, $row);
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    public function render()
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        return view('livewire.payroll.process', [
            'payslips' => $this->payslips,
            'departments' => $this->departments,
            'offices' => $this->offices,
        ])->layout('layouts.app', ['title' => 'Run Payroll']);
    }
}
