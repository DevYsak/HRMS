<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\Reimbursement;
use App\Services\ReimbursementService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class Reimbursements extends Component
{
    use WithFileUploads;
    use WithPagination;

    // Modal
    public bool $showModal = false;

    // Form fields
    public int $employeeId = 0;

    public string $title = '';

    public string $description = '';

    public float $amount = 0;

    public string $expenseDate = '';

    public string $month = '';

    public string $category = 'general';

    public $receipt; // uploaded file

    // Filters
    public string $filterStatus = '';

    public string $filterMonth = '';

    public function mount(): void
    {
        $this->month = Carbon::now()->format('Y-m');
        $this->filterMonth = Carbon::now()->format('Y-m');
        $this->expenseDate = Carbon::today()->toDateString();
    }

    public function openModal(): void
    {
        $this->reset(['employeeId', 'title', 'description', 'amount', 'receipt']);
        $this->resetErrorBag();
        $this->expenseDate = Carbon::today()->toDateString();
        $this->month = Carbon::now()->format('Y-m');
        $this->category = 'general';
        $this->amount = 0;
        $this->showModal = true;
    }

    public function submit(ReimbursementService $reimbursementService): void
    {
        $this->validate([
            'employeeId' => ['required', 'exists:employees,id'],
            'title' => ['required', 'string', 'max:191'],
            'amount' => ['required', 'numeric', 'min:1'],
            'expenseDate' => ['required', 'date', 'before_or_equal:today'],
            'month' => ['required', 'date_format:Y-m'],
            'category' => ['required', 'string'],
            'receipt' => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $receiptPath = null;
        if ($this->receipt) {
            $receiptPath = $this->receipt->store('reimbursements', 'local');
        }

        $employee = Employee::findOrFail($this->employeeId);
        $reimbursementService->submit($employee, [
            'title' => $this->title,
            'description' => $this->description,
            'amount' => $this->amount,
            'expense_date' => $this->expenseDate,
            'month' => $this->month,
            'category' => $this->category,
            'receipt_path' => $receiptPath,
        ]);

        $this->showModal = false;
        $this->reset(['employeeId', 'title', 'description', 'amount', 'expenseDate', 'category', 'receipt']);
        \Flux::toast('Reimbursement submitted for approval.');
    }

    public function approve(int $id, ReimbursementService $reimbursementService): void
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $reimbursementService->approve($reimbursement, Auth::id());
        \Flux::toast('Reimbursement approved.');
    }

    public function reject(int $id, ReimbursementService $reimbursementService): void
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $reimbursementService->reject($reimbursement, Auth::id());
        \Flux::toast('Reimbursement rejected.', variant: 'danger');
    }

    public function render()
    {
        $reimbursements = Reimbursement::with(['employee.user', 'approvedBy'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterMonth, fn ($q) => $q->where('month', $this->filterMonth))
            ->latest()
            ->paginate(20);

        $employees = Employee::with('user')->where('status', 'active')->get();

        return view('livewire.payroll.reimbursements', compact('reimbursements', 'employees'))
            ->layout('layouts.app', ['title' => 'Reimbursements']);
    }
}
