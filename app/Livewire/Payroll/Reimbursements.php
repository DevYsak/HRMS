<?php

namespace App\Livewire\Payroll;

use App\Models\Employee;
use App\Models\Reimbursement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

class Reimbursements extends Component
{
    use WithFileUploads;

    // Form fields
    public int    $employeeId  = 0;
    public string $title       = '';
    public string $description = '';
    public float  $amount      = 0;
    public string $expenseDate = '';
    public string $month       = '';
    public string $category    = 'general';
    public $receipt; // uploaded file

    // Filters
    public string $filterStatus = '';
    public string $filterMonth  = '';

    public function mount(): void
    {
        $this->month       = Carbon::now()->format('Y-m');
        $this->filterMonth = Carbon::now()->format('Y-m');
        $this->expenseDate = Carbon::today()->toDateString();
    }

    public function submit(): void
    {
        $this->validate([
            'employeeId'  => ['required', 'exists:employees,id'],
            'title'       => ['required', 'string', 'max:191'],
            'amount'      => ['required', 'numeric', 'min:1'],
            'expenseDate' => ['required', 'date', 'before_or_equal:today'],
            'month'       => ['required', 'date_format:Y-m'],
            'category'    => ['required', 'string'],
            'receipt'     => ['nullable', 'file', 'max:5120', 'mimes:jpg,jpeg,png,pdf'],
        ]);

        $receiptPath = null;
        if ($this->receipt) {
            $receiptPath = $this->receipt->store('reimbursements', 'private');
        }

        Reimbursement::create([
            'employee_id'  => $this->employeeId,
            'title'        => $this->title,
            'description'  => $this->description,
            'amount'       => $this->amount,
            'expense_date' => $this->expenseDate,
            'month'        => $this->month,
            'category'     => $this->category,
            'receipt_path' => $receiptPath,
            'status'       => 'pending',
        ]);

        $this->reset(['employeeId', 'title', 'description', 'amount', 'expenseDate', 'category', 'receipt']);
        $this->dispatch('flux:modal:close', name: 'reimbursement-modal');
        \Flux::toast('Reimbursement claim submitted for approval.');
    }

    public function approve(int $id): void
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $reimbursement->update([
            'status'       => 'approved',
            'approved_by'  => Auth::id(),
            'approved_at'  => Carbon::now(),
        ]);
        \Flux::toast('Reimbursement approved.');
    }

    public function reject(int $id): void
    {
        $reimbursement = Reimbursement::findOrFail($id);
        $reimbursement->update([
            'status'      => 'rejected',
            'approved_by' => Auth::id(),
            'approved_at' => Carbon::now(),
        ]);
        \Flux::toast('Reimbursement rejected.', variant: 'danger');
    }

    public function render()
    {
        $reimbursements = Reimbursement::with(['employee.user', 'approvedBy'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->filterMonth,  fn ($q) => $q->where('month', $this->filterMonth))
            ->latest()
            ->paginate(20);

        $employees = Employee::with('user')->where('status', 'active')->get();

        return view('livewire.payroll.reimbursements', compact('reimbursements', 'employees'))
            ->layout('layouts.app', ['title' => 'Reimbursements']);
    }
}
