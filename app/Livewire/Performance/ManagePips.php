<?php

namespace App\Livewire\Performance;

use App\Models\Document;
use App\Models\Employee;
use App\Models\PipGoal;
use App\Models\PipRecord;
use App\Services\Approvals\ApprovalGuard;
use App\Services\Performance\PipService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

class ManagePips extends Component
{
    use WithFileUploads;
    use WithPagination;

    public string $search = '';

    public string $status = '';

    // Modals
    public bool $showCreateModal = false;

    public bool $showViewModal = false;

    public bool $showGoalModal = false;

    public bool $showProgressModal = false;

    public bool $showOutcomeModal = false;

    public ?PipRecord $activeRecord = null;

    // Create form
    public $employee_id = '';

    public $start_date = '';

    public $end_date = '';

    public $review_period_days = 90;

    public $action_plan = '';

    public $success_criteria = '';

    // Goal form
    public $goal_title = '';

    public $goal_description = '';

    public $goal_target_date = '';

    public $goal_weightage = 0;

    // Progress form
    #[Locked]
    public ?int $progressGoalId = null;

    public $progress_percent = 0;

    public $progress_notes = '';

    // Outcome form
    public $outcome = '';

    // Document upload form
    public bool $showDocUploadModal = false;

    public string $doc_title = '';

    public string $doc_description = '';

    public $doc_file = null;

    public function mount(): void
    {
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->addDays(90)->format('Y-m-d');
    }

    public function openCreateModal(): void
    {
        $this->checkPermission();
        $this->reset(['employee_id', 'action_plan', 'success_criteria']);
        $this->review_period_days = 90;
        $this->start_date = now()->format('Y-m-d');
        $this->end_date = now()->addDays(90)->format('Y-m-d');
        $this->resetErrorBag();
        $this->showCreateModal = true;
    }

    public function create(PipService $service): void
    {
        $this->checkPermission();

        $this->validate([
            'employee_id' => 'required|exists:employees,id',
            'review_period_days' => 'required|integer|min:1|max:365',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'action_plan' => 'nullable|string|max:2000',
            'success_criteria' => 'nullable|string|max:2000',
        ]);

        $employee = Employee::findOrFail($this->employee_id);
        app(ApprovalGuard::class)->assertCanDecide(Auth::user(), $employee);

        $service->create($employee, [
            'review_period_days' => (int) $this->review_period_days,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'action_plan' => $this->action_plan ?: null,
            'success_criteria' => $this->success_criteria ?: null,
        ], Auth::user());

        $this->showCreateModal = false;
        \Flux::toast('Improvement plan drafted successfully.', variant: 'success');
    }

    public function viewRecord(int $id): void
    {
        $this->checkPermission();
        $this->activeRecord = $this->inReach(PipRecord::with(['employee.user', 'employee.jobTitle', 'manager', 'hrReviewer', 'goals', 'documents']))->findOrFail($id);
        $this->showGoalModal = false;
        $this->showProgressModal = false;
        $this->showOutcomeModal = false;
        $this->showDocUploadModal = false;
        $this->showViewModal = true;
    }

    public function openDocUploadModal(): void
    {
        $this->checkPermission();
        $this->reset(['doc_title', 'doc_description', 'doc_file']);
        $this->resetErrorBag();
        $this->showDocUploadModal = true;
    }

    public function uploadDocument(): void
    {
        $this->checkPermission();

        $this->validate([
            'doc_title' => 'required|string|max:255',
            'doc_description' => 'nullable|string|max:1000',
            'doc_file' => 'required|file|max:10240',
        ]);

        $path = $this->doc_file->store("documents/pip/{$this->activeRecord->id}", 'local');

        $this->activeRecord->documents()->create([
            'title' => $this->doc_title,
            'description' => $this->doc_description ?: null,
            'file_path' => $path,
            'file_name' => $this->doc_file->getClientOriginalName(),
            'mime_type' => $this->doc_file->getMimeType(),
            'file_size' => $this->doc_file->getSize(),
            'category' => 'pip',
            'visibility' => 'restricted',
            'employee_id' => $this->activeRecord->employee_id,
            'requires_acknowledgement' => true,
            'uploaded_by' => Auth::id(),
        ]);

        $this->showDocUploadModal = false;
        $this->viewRecord($this->activeRecord->id);
        \Flux::toast('Document uploaded and stored in employee documents.', variant: 'success');
    }

    public function deleteDocument(int $documentId): void
    {
        $this->checkPermission();

        $document = Document::where('documentable_type', PipRecord::class)
            ->where('documentable_id', $this->activeRecord->id)
            ->findOrFail($documentId);

        Storage::disk('local')->delete($document->file_path);
        $document->delete();

        $this->viewRecord($this->activeRecord->id);
        \Flux::toast('Document deleted.', variant: 'warning');
    }

    public function activate(PipService $service): void
    {
        $this->checkPermission();
        $this->assertActiveRecordInReach();

        try {
            $service->activate($this->activeRecord, Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Improvement plan activated.', variant: 'success');
        $this->viewRecord($this->activeRecord->id);
    }

    public function openGoalModal(): void
    {
        $this->reset(['goal_title', 'goal_description', 'goal_target_date']);
        $this->goal_weightage = 0;
        $this->resetErrorBag();
        $this->showGoalModal = true;
    }

    public function addGoal(PipService $service): void
    {
        $this->checkPermission();
        $this->assertActiveRecordInReach();

        $this->validate([
            'goal_title' => 'required|string|max:255',
            'goal_description' => 'nullable|string|max:1000',
            'goal_target_date' => 'nullable|date',
            'goal_weightage' => 'required|numeric|min:0|max:100',
        ]);

        try {
            $service->addGoal($this->activeRecord, [
                'title' => $this->goal_title,
                'description' => $this->goal_description ?: null,
                'target_date' => $this->goal_target_date ?: null,
                'weightage' => (float) $this->goal_weightage,
            ]);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showGoalModal = false;
        \Flux::toast('Goal added.', variant: 'success');
        $this->viewRecord($this->activeRecord->id);
    }

    public function openProgressModal(int $goalId): void
    {
        $this->checkPermission();
        $goal = $this->activeGoals()->findOrFail($goalId);
        $this->progressGoalId = $goal->id;
        $this->progress_percent = $goal->progress_percent;
        $this->progress_notes = $goal->manager_notes ?? '';
        $this->resetErrorBag();
        $this->showProgressModal = true;
    }

    public function updateProgress(PipService $service): void
    {
        $this->validate([
            'progress_percent' => 'required|numeric|min:0|max:100',
            'progress_notes' => 'nullable|string|max:1000',
        ]);

        $this->checkPermission();
        $goal = $this->activeGoals()->findOrFail($this->progressGoalId);

        $service->updateGoalProgress($goal, (float) $this->progress_percent, $this->progress_notes ?: null, Auth::user());

        $this->showProgressModal = false;
        \Flux::toast('Goal progress updated.', variant: 'success');
        $this->viewRecord($this->activeRecord->id);
    }

    public function openOutcomeModal(): void
    {
        $this->outcome = '';
        $this->resetErrorBag();
        $this->showOutcomeModal = true;
    }

    public function recordOutcome(PipService $service): void
    {
        $this->checkPermission();
        $this->assertActiveRecordInReach();

        $this->validate([
            'outcome' => 'required|in:successful,extended,failed,escalated',
        ]);

        try {
            $service->recordOutcome($this->activeRecord, $this->outcome, Auth::user());
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showOutcomeModal = false;
        \Flux::toast('Outcome recorded.', variant: 'success');
        $this->viewRecord($this->activeRecord->id);
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->resetPage();
    }

    public function checkPermission(): void
    {
        $user = Auth::user();
        abort_unless($user->canManageEmployees() || $user->canReviewPerformance(), 403);
    }

    /**
     * PIP records inside the user's reach (reporting line / HR scope) and
     * never the user's own — nobody manages their own improvement plan.
     *
     * @template TQuery of \Illuminate\Database\Eloquent\Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    protected function inReach($query)
    {
        $ids = Auth::user()->accessibleEmployeeIds();
        $own = Auth::user()->employee?->id;

        return $query
            ->when($ids !== null, fn ($q) => $q->whereIn('employee_id', $ids))
            ->when($own, fn ($q) => $q->where('employee_id', '!=', $own));
    }

    /** Goals of the open PIP only — a goal id from the client cannot reach another plan. */
    protected function activeGoals()
    {
        return PipGoal::where('pip_record_id', $this->activeRecord?->id ?? 0);
    }

    protected function assertActiveRecordInReach(): void
    {
        abort_unless($this->activeRecord !== null, 422);
        app(ApprovalGuard::class)->assertCanDecide(Auth::user(), $this->activeRecord->employee);
    }

    public function render()
    {
        $this->checkPermission();

        $scopeIds = Auth::user()->accessibleEmployeeIds();

        $query = $this->inReach(PipRecord::with(['employee.user', 'employee.jobTitle', 'manager', 'goals']))
            ->when($this->search, function ($q) {
                $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%"));
            })
            ->when($this->status, fn ($q) => $q->where('status', $this->status));

        $dueThisWeek = $this->inReach(PipRecord::query())->where('status', 'active')
            ->whereBetween('end_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->count();

        $overdueReviews = $this->inReach(PipRecord::query())->where('status', 'active')
            ->where('end_date', '<', now()->toDateString())
            ->count();

        $completedReviews = $this->inReach(PipRecord::query())->whereIn('status', ['successful', 'failed', 'extended', 'escalated'])
            ->whereMonth('outcome_date', now()->month)
            ->whereYear('outcome_date', now()->year)
            ->count();

        return view('livewire.performance.manage-pips', [
            'records' => $query->latest('start_date')->paginate(15),
            'employees' => Employee::with('user')->where('status', 'active')
                ->when($scopeIds !== null, fn ($q) => $q->whereIn('id', $scopeIds))
                ->where('user_id', '!=', Auth::id())
                ->get(),
            'dueThisWeek' => $dueThisWeek,
            'overdueReviews' => $overdueReviews,
            'completedReviews' => $completedReviews,
        ])->layout('layouts.app', ['title' => 'Manage Improvement Plans']);
    }
}
