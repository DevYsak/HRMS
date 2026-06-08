<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceReview;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyReview extends Component
{
    public $activeTab = 'reviews';

    public $showSelfReviewModal = false;

    public ?PerformanceReview $activeReview = null;

    // Form fields
    public string $strengths = '';

    public string $improvements = '';

    public string $comments = '';

    public array $componentScores = []; // [component_id => self_score]

    public array $componentComments = []; // [component_id => self_comment]

    public function openReview(int $reviewId): void
    {
        $this->activeReview = PerformanceReview::with(['componentScores.component.autoScoreConfig', 'template.categories.components', 'documents'])->findOrFail($reviewId);

        $this->strengths = $this->activeReview->strengths ?? '';
        $this->improvements = $this->activeReview->improvements ?? '';
        $this->comments = $this->activeReview->comments ?? '';

        $this->componentScores = [];
        $this->componentComments = [];

        foreach ($this->activeReview->componentScores as $score) {
            $this->componentScores[$score->component_id] = $score->self_score ?? '';
            $this->componentComments[$score->component_id] = $score->self_comment ?? '';
        }

        $this->showSelfReviewModal = true;
    }

    public function submitSelfReview(ReviewWorkflowService $workflowService): void
    {
        if (! $this->activeReview) {
            return;
        }

        $this->activeReview->loadMissing('performanceCycle');

        // Setup validation rules dynamically based on non-auto components
        $rules = [
            'strengths' => 'required|string|min:10',
            'improvements' => 'required|string|min:10',
            'comments' => 'nullable|string',
        ];

        foreach ($this->activeReview->componentScores as $score) {
            if (! $score->component->isAutoScored()) {
                $rules["componentScores.{$score->component_id}"] = "required|numeric|min:0|max:{$score->component->max_score}";
                $rules["componentComments.{$score->component_id}"] = 'nullable|string';
            }
        }

        $this->validate($rules);

        // Save fields not handled by Workflow service
        $this->activeReview->update([
            'strengths' => $this->strengths,
            'improvements' => $this->improvements,
            'comments' => $this->comments,
        ]);

        $scores = [];
        foreach ($this->componentScores as $componentId => $scoreVal) {
            // Only submit if it's set and valid
            if ($scoreVal !== '') {
                $scores[] = [
                    'component_id' => $componentId,
                    'self_score' => (float) $scoreVal,
                    'self_comment' => $this->componentComments[$componentId] ?? null,
                ];
            }
        }

        try {
            $workflowService->submitSelfReview($this->activeReview, $scores, Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->showSelfReviewModal = false;
        $this->reset(['strengths', 'improvements', 'comments', 'activeReview', 'componentScores', 'componentComments']);
        \Flux::toast('Self-review submitted successfully!', variant: 'success');
    }

    public function render()
    {
        $employee = Auth::user()->employee;

        if (! $employee) {
            return view('livewire.performance.my-review', [
                'activeReviews' => collect(),
                'completedReviews' => collect(),
            ])->layout('layouts.app', ['title' => 'My Performance Review']);
        }

        $activeReviews = PerformanceReview::with(['performanceCycle', 'reviewer.user'])
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['draft', 'submitted', 'manager_reviewed', 'hr_reviewed'])
            ->latest()
            ->get();

        $completedReviews = PerformanceReview::with(['performanceCycle', 'reviewer.user'])
            ->where('employee_id', $employee->id)
            ->where('status', 'locked')
            ->latest()
            ->get();

        return view('livewire.performance.my-review', [
            'activeReviews' => $activeReviews,
            'completedReviews' => $completedReviews,
        ])->layout('layouts.app', ['title' => 'My Performance Review']);
    }
}
