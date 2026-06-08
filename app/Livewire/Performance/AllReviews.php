<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceCycle;
use App\Models\PerformanceReview;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class AllReviews extends Component
{
    use WithPagination;

    public string $search = '';

    public string $status = '';

    public string $cycle_id = '';

    public bool $showViewModal = false;

    public ?PerformanceReview $viewingReview = null;

    // Form fields for HR Validation
    public string $hr_comments = '';

    public array $componentScores = []; // [component_id => score]

    public array $componentComments = []; // [component_id => comment]

    public function viewReview(int $id): void
    {
        abort_unless(auth()->user()->canManageEmployees(), 403);
        $this->viewingReview = PerformanceReview::with([
            'employee.user', 'employee.jobTitle', 'performanceCycle',
            'reviewer.user', 'componentScores.component.autoScoreConfig', 'template.categories.components', 'documents',
        ])->findOrFail($id);

        $this->hr_comments = $this->viewingReview->hr_comments ?? '';

        $this->componentScores = [];
        $this->componentComments = [];
        foreach ($this->viewingReview->componentScores as $score) {
            // Pre-fill with existing HR score, or fallback to manager score or self score
            $this->componentScores[$score->component_id] = $score->hr_score ?? $score->manager_score ?? $score->self_score ?? '';
            $this->componentComments[$score->component_id] = $score->hr_comment ?? '';
        }

        $this->showViewModal = true;
    }

    public function submitHrReview(ReviewWorkflowService $workflowService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        if (! $this->viewingReview) {
            return;
        }

        $rules = [
            'hr_comments' => 'required|string',
        ];

        foreach ($this->viewingReview->componentScores as $score) {
            if (! $score->component->isAutoScored()) {
                $rules["componentScores.{$score->component_id}"] = "required|numeric|min:0|max:{$score->component->max_score}";
                $rules["componentComments.{$score->component_id}"] = 'nullable|string';
            }
        }

        $this->validate($rules);

        $scores = [];
        foreach ($this->componentScores as $componentId => $scoreVal) {
            if ($scoreVal !== '') {
                $scores[] = [
                    'component_id' => $componentId,
                    'hr_score' => (float) $scoreVal,
                    'hr_comment' => $this->componentComments[$componentId] ?? null,
                ];
            }
        }

        try {
            $workflowService->submitHrReview($this->viewingReview, $scores, $this->hr_comments, Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->showViewModal = false;
        \Flux::toast('HR Validation submitted successfully.', variant: 'success');
    }

    public function lockReview(ReviewWorkflowService $workflowService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        if (! $this->viewingReview) {
            return;
        }

        // Must be hr_reviewed to lock it, but if we are HR we can lock manager_reviewed too.
        // The workflow allows locking if status is 'manager_reviewed' or 'hr_reviewed'.

        try {
            $workflowService->lockReview($this->viewingReview, Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->showViewModal = false;
        \Flux::toast('Review locked successfully. Scorecard generated.', variant: 'success');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $reviews = PerformanceReview::with(['employee.user', 'employee.jobTitle', 'performanceCycle', 'reviewer.user'])
            ->when($this->search, fn ($q) => $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->cycle_id, fn ($q) => $q->where('performance_cycle_id', $this->cycle_id))
            ->latest()
            ->paginate(20);

        return view('livewire.performance.all-reviews', [
            'reviews' => $reviews,
            'cycles' => PerformanceCycle::orderByDesc('start_date')->get(),
        ])->layout('layouts.app', ['title' => 'All Performance Reviews']);
    }
}
