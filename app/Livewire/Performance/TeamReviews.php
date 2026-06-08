<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceReview;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TeamReviews extends Component
{
    public string $search = '';

    public $showReviewModal = false;

    public ?PerformanceReview $activeReview = null;

    // Form fields
    public string $manager_feedback = '';

    public bool $promotion_recommended = false;

    public array $componentScores = []; // [component_id => score]

    public array $componentComments = []; // [component_id => comment]

    public function openManagerReview(int $reviewId): void
    {
        $this->activeReview = PerformanceReview::with(['componentScores.component.autoScoreConfig', 'template.categories.components', 'employee.user', 'documents'])->findOrFail($reviewId);

        $this->manager_feedback = $this->activeReview->manager_feedback ?? '';
        $this->promotion_recommended = $this->activeReview->promotion_recommended ?? false;

        $this->componentScores = [];
        $this->componentComments = [];
        foreach ($this->activeReview->componentScores as $score) {
            $this->componentScores[$score->component_id] = $score->manager_score ?? '';
            $this->componentComments[$score->component_id] = $score->manager_comment ?? '';
        }

        $this->showReviewModal = true;
    }

    public function submitManagerReview(ReviewWorkflowService $workflowService): void
    {
        if (! $this->activeReview) {
            return;
        }

        $this->activeReview->loadMissing('performanceCycle');

        $rules = [
            'manager_feedback' => 'required|string|min:10',
            'promotion_recommended' => 'required|boolean',
        ];

        foreach ($this->activeReview->componentScores as $score) {
            if (! $score->component->isAutoScored()) {
                $rules["componentScores.{$score->component_id}"] = "required|numeric|min:0|max:{$score->component->max_score}";
                $rules["componentComments.{$score->component_id}"] = 'nullable|string';
            }
        }

        $this->validate($rules);

        // Update fields not managed by workflow service
        $this->activeReview->update([
            'promotion_recommended' => $this->promotion_recommended,
        ]);

        $scores = [];
        foreach ($this->componentScores as $componentId => $scoreVal) {
            if ($scoreVal !== '') {
                $scores[] = [
                    'component_id' => $componentId,
                    'manager_score' => (float) $scoreVal,
                    'manager_comment' => $this->componentComments[$componentId] ?? null,
                ];
            }
        }

        try {
            $workflowService->submitManagerReview($this->activeReview, $scores, $this->manager_feedback, Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->showReviewModal = false;
        $this->reset(['manager_feedback', 'promotion_recommended', 'activeReview', 'componentScores', 'componentComments']);
        \Flux::toast('Manager review submitted.', variant: 'success');
    }

    public function render()
    {
        $user = Auth::user();
        $managerEmployee = $user->employee;

        if (! $managerEmployee) {
            return view('livewire.performance.team-reviews', [
                'teamReviews' => collect(),
            ])->layout('layouts.app', ['title' => 'Team Performance Reviews']);
        }

        // Get direct reports' pending reviews
        $teamReviews = PerformanceReview::with(['employee.user', 'employee.jobTitle', 'performanceCycle'])
            ->where('reviewer_id', $managerEmployee->id)
            ->whereIn('status', ['submitted', 'manager_reviewed'])
            ->when($this->search, fn ($q) => $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")))
            ->latest()
            ->get();

        return view('livewire.performance.team-reviews', [
            'teamReviews' => $teamReviews,
        ])->layout('layouts.app', ['title' => 'Team Performance Reviews']);
    }
}
