<?php

namespace App\Livewire\Operations;

use App\Enums\ExpenseStatus;
use App\Models\ExpenseClaim;
use App\Services\ExpenseClaimService;
use App\Services\ReimbursementService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithFileUploads;

class Expenses extends Component
{
    use WithFileUploads;

    public bool $showSubmitModal = false;

    public string $title = '';

    public string $category = 'general';

    public float $amount = 0;

    public string $expenseDate = '';

    public string $notes = '';

    public $receipt = null;

    public bool $showRejectModal = false;

    public ?int $reviewingId = null;

    public string $rejectionReason = '';

    public function mount(): void
    {
        $this->expenseDate = now()->toDateString();
    }

    public function openSubmitModal(): void
    {
        $this->showSubmitModal = true;
    }

    public function submit(ExpenseClaimService $expenseClaimService): void
    {
        $employee = Auth::user()->employee;
        abort_unless($employee, 403);

        $this->validate([
            'title' => ['required', 'string', 'max:191'],
            'category' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'min:1'],
            'expenseDate' => ['required', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'receipt' => ['required', 'file', 'max:5120', 'mimes:pdf,jpg,jpeg,png,webp'],
        ]);

        $receiptPath = $this->receipt?->store('expense-claims', 'private');

        $expenseClaimService->submit($employee, [
            'title' => $this->title,
            'category' => $this->category,
            'amount' => $this->amount,
            'expense_date' => $this->expenseDate,
            'notes' => $this->notes ?: null,
            'receipt_path' => $receiptPath,
        ]);

        $this->reset(['title', 'category', 'amount', 'notes', 'receipt']);
        $this->category = 'general';
        $this->amount = 0;
        $this->expenseDate = now()->toDateString();
        $this->showSubmitModal = false;
        \Flux::toast('Expense claim submitted for manager approval.');
    }

    public function approve(int $id, ExpenseClaimService $expenseClaimService, ReimbursementService $reimbursementService): void
    {
        abort_unless($this->canReviewClaims(), 403);

        $claim = ExpenseClaim::with('employee')->findOrFail($id);
        abort_unless($this->canReviewClaim($claim), 403);

        try {
            $expenseClaimService->approve($claim, Auth::id(), $reimbursementService);
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Expense approved and converted to reimbursement.');
    }

    public function openRejectModal(int $id): void
    {
        abort_unless($this->canReviewClaims(), 403);
        $this->reviewingId = $id;
        $this->rejectionReason = '';
        $this->showRejectModal = true;
    }

    public function reject(ExpenseClaimService $expenseClaimService): void
    {
        abort_unless($this->canReviewClaims() && $this->reviewingId !== null, 403);

        $claim = ExpenseClaim::with('employee')->findOrFail($this->reviewingId);
        abort_unless($this->canReviewClaim($claim), 403);

        $reason = trim($this->rejectionReason) ?: 'Rejected by reviewer.';
        try {
            $expenseClaimService->reject($claim, Auth::id(), $reason);
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }
        $this->rejectionReason = '';
        $this->showRejectModal = false;
        $this->reviewingId = null;

        \Flux::toast('Expense claim rejected.', variant: 'danger');
    }

    protected function canReviewClaims(): bool
    {
        return Auth::user()->canApproveLeave() || Auth::user()->canManageEmployees();
    }

    protected function canReviewClaim(ExpenseClaim $claim): bool
    {
        if (Auth::user()->canManageEmployees()) {
            return true;
        }

        return (int) ($claim->employee?->manager_id ?? 0) === (int) Auth::id();
    }

    public function render()
    {
        $user = Auth::user();
        abort_unless($user, 403);

        $expensesQuery = ExpenseClaim::with('employee.user', 'approver');

        if (! $this->canReviewClaims()) {
            $employeeId = $user->employee?->id;
            $expensesQuery->where('employee_id', $employeeId ?: 0);
        } elseif (! $user->canManageEmployees()) {
            $expensesQuery->whereHas('employee', fn ($query) => $query->where('manager_id', $user->id));
        }

        $expenses = $expensesQuery->latest()->get();

        return view('livewire.operations.expenses', [
            'expenses' => $expenses,
            'canReview' => $this->canReviewClaims(),
            'pendingStatus' => ExpenseStatus::Pending->value,
        ])->layout('layouts.app', ['title' => 'Expense Claims']);
    }
}
