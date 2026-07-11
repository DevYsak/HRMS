<?php

namespace App\Livewire\TimeOff;

use App\Livewire\Concerns\HandlesClaimLock;
use App\Models\LeaveEncashment;
use App\Services\LeaveService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class FinanceEncashments extends Component
{
    use HandlesClaimLock;
    use WithPagination;

    public string $filterStatus = '';

    public string $search = '';

    // Review modal
    public bool $showReviewModal = false;

    public ?int $reviewingId = null;

    public string $reviewComment = '';

    public string $reviewAction = ''; // approve | reject

    protected function rules(): array
    {
        return [
            'reviewComment' => $this->reviewAction === 'reject' ? 'required|string|max:500' : 'nullable|string|max:500',
        ];
    }

    public function openReview(int $id, string $action): void
    {
        // Claim the HR-stage request so a second HR can't process it too.
        $encashment = LeaveEncashment::with('claimer')->findOrFail($id);
        if (! $this->claimForReview($encashment)) {
            return;
        }

        $this->reviewingId = $id;
        $this->reviewAction = $action;
        $this->reviewComment = '';
        $this->showReviewModal = true;
    }

    public function submitReview(): void
    {
        $this->validate();

        $encashment = LeaveEncashment::findOrFail($this->reviewingId);
        $user = Auth::user();
        $service = app(LeaveService::class);

        try {
            if ($this->reviewAction === 'approve') {
                if ($encashment->isPending()) {
                    // HR/manager first-stage approval
                    abort_unless($user->canApproveLeave(), 403);
                    $service->approveEncashment($user, $encashment, $this->reviewComment ?? '');
                    \Flux::toast('Encashment forwarded to finance for final approval.');
                } elseif ($encashment->isPendingFinance()) {
                    // Finance final approval
                    abort_unless($user->canApproveFinance(), 403);
                    $service->financeApproveEncashment($user, $encashment, $this->reviewComment ?? '');
                    \Flux::toast('Encashment approved. Will be included in payroll for the selected month.');
                } else {
                    \Flux::toast('This encashment is no longer actionable.', variant: 'warning');
                }
            } else {
                abort_unless($user->canApproveLeave() || $user->canApproveFinance(), 403);
                $service->rejectEncashment($user, $encashment, $this->reviewComment);
                \Flux::toast('Encashment rejected.');
            }
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');
        }

        $this->releaseClaim($encashment);
        $this->showReviewModal = false;
        $this->reset(['reviewingId', 'reviewAction', 'reviewComment']);
    }

    public function render()
    {
        $user = Auth::user();
        abort_unless($user->canApproveLeave() || $user->canApproveFinance(), 403);

        $query = LeaveEncashment::with(['employee.user', 'leaveType', 'reviewer', 'financeReviewer'])
            ->when($this->filterStatus, fn ($q) => $q->where('status', $this->filterStatus))
            ->when($this->search, function ($q) {
                $q->whereHas('employee.user', fn ($sub) => $sub->where('name', 'like', '%'.$this->search.'%'));
            });

        // Finance only sees pending_finance and beyond; HR/manager sees all
        if ($user->canApproveFinance() && ! $user->canApproveLeave()) {
            $query->whereIn('status', ['pending_finance', 'approved', 'rejected', 'processed']);
        }

        $encashments = $query->latest()->paginate(15);

        $kpi = [
            'pending' => LeaveEncashment::where('status', 'pending')->count(),
            'pending_finance' => LeaveEncashment::where('status', 'pending_finance')->count(),
            'approved_ytd' => LeaveEncashment::where('status', 'approved')->whereYear('created_at', now()->year)->count(),
            'processed_ytd' => LeaveEncashment::where('status', 'processed')->whereYear('created_at', now()->year)->count(),
        ];

        $reviewingEncashment = $this->reviewingId
            ? LeaveEncashment::with(['employee.user', 'leaveType'])->find($this->reviewingId)
            : null;

        return view('livewire.time-off.finance-encashments', compact('encashments', 'kpi', 'reviewingEncashment'))
            ->layout('layouts.app', ['title' => 'Leave Encashments']);
    }
}
