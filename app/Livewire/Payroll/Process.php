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

    public function exportExcel(): void
    {
        \Flux::toast('Export feature coming soon.', variant: 'warning');
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
