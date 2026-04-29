<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceReview;
use App\Models\ReviewGoal;
use App\Services\PerformanceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class MyReview extends Component
{
    public $activeTab = 'reviews';

    public $showSelfReviewModal = false;

    public ?PerformanceReview $activeReview = null;

    // Form fields
    public int $rating = 0;

    public string $strengths = '';

    public string $improvements = '';

    public string $comments = '';

    public array $goalRatings = []; // [goal_id => rating]

    public array $goalComments = []; // [goal_id => comment]

    public function openReview(int $reviewId): void
    {
        $this->activeReview = PerformanceReview::with('goals')->findOrFail($reviewId);

        $this->rating = $this->activeReview->overall_rating ?? 0;
        $this->strengths = $this->activeReview->strengths ?? '';
        $this->improvements = $this->activeReview->improvements ?? '';
        $this->comments = $this->activeReview->comments ?? '';

        $this->goalRatings = [];
        $this->goalComments = [];
        foreach ($this->activeReview->goals as $goal) {
            $this->goalRatings[$goal->id] = $goal->self_rating ?? 0;
            $this->goalComments[$goal->id] = $goal->self_comment ?? '';
        }

        $this->showSelfReviewModal = true;
    }

    public function submitSelfReview(PerformanceService $performanceService): void
    {
        if (! $this->activeReview) {
            return;
        }

        $this->activeReview->loadMissing('cycle');
        try {
            $performanceService->assertReviewEditable($this->activeReview, 'draft');
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'strengths' => 'required|string|min:10',
            'improvements' => 'required|string|min:10',
            'comments' => 'nullable|string',
            'goalRatings.*' => 'required|integer|min:1|max:5',
            'goalComments.*' => 'nullable|string',
        ]);

        DB::transaction(function () {
            // Update goals
            foreach ($this->goalRatings as $goalId => $rating) {
                ReviewGoal::where('id', $goalId)
                    ->where('performance_review_id', $this->activeReview->id)
                    ->update([
                        'self_rating' => $rating,
                        'self_comment' => $this->goalComments[$goalId] ?? null,
                    ]);
            }

            // Update review
            $this->activeReview->update([
                'overall_rating' => $this->rating,
                'strengths' => $this->strengths,
                'improvements' => $this->improvements,
                'comments' => $this->comments,
                'status' => 'submitted',
                'submitted_at' => now(),
            ]);
        });

        $this->showSelfReviewModal = false;
        $this->reset(['rating', 'strengths', 'improvements', 'comments', 'activeReview', 'goalRatings', 'goalComments']);
        \Flux::toast('Self-review submitted successfully!');
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

        $activeReviews = PerformanceReview::with(['cycle', 'reviewer.user'])
            ->where('employee_id', $employee->id)
            ->whereIn('status', ['draft', 'submitted', 'manager_reviewed'])
            ->latest()
            ->get();

        $completedReviews = PerformanceReview::with(['cycle', 'reviewer.user'])
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
