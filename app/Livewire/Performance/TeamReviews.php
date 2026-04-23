<?php

namespace App\Livewire\Performance;

use App\Models\Employee;
use App\Models\PerformanceReview;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class TeamReviews extends Component
{
    public $showReviewModal = false;
    public ?PerformanceReview $activeReview = null;

    // Form fields
    public int $rating = 0;
    public string $strengths = '';
    public string $improvements = '';
    public string $comments = '';
    public string $manager_feedback = '';
    public bool $promotion_recommended = false;
    public array $goalRatings = []; // [goal_id => rating]
    public array $goalComments = []; // [goal_id => comment]

    public function openManagerReview(int $reviewId): void
    {
        $this->activeReview = PerformanceReview::with('goals', 'employee.user')->findOrFail($reviewId);
        
        $this->rating = $this->activeReview->overall_rating ?? 0;
        $this->strengths = $this->activeReview->strengths ?? '';
        $this->improvements = $this->activeReview->improvements ?? '';
        $this->comments = $this->activeReview->comments ?? '';
        $this->manager_feedback = $this->activeReview->manager_feedback ?? '';
        $this->promotion_recommended = $this->activeReview->promotion_recommended;

        $this->goalRatings = [];
        $this->goalComments = [];
        foreach ($this->activeReview->goals as $goal) {
            $this->goalRatings[$goal->id] = $goal->manager_rating ?? 0;
            $this->goalComments[$goal->id] = $goal->manager_comment ?? '';
        }

        $this->showReviewModal = true;
    }

    public function submitManagerReview(): void
    {
        if (!$this->activeReview || $this->activeReview->status !== 'submitted') {
            return;
        }

        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'manager_feedback' => 'required|string|min:10',
            'promotion_recommended' => 'required|boolean',
            'goalRatings.*' => 'required|integer|min:1|max:5',
            'goalComments.*' => 'nullable|string',
        ]);

        \Illuminate\Support\Facades\DB::transaction(function () {
            // Update goals
            foreach ($this->goalRatings as $goalId => $rating) {
                \App\Models\ReviewGoal::where('id', $goalId)
                    ->where('performance_review_id', $this->activeReview->id)
                    ->update([
                        'manager_rating' => $rating,
                        'manager_comment' => $this->goalComments[$goalId] ?? null,
                    ]);
            }

            // Update review
            $this->activeReview->update([
                'overall_rating' => $this->rating,
                'manager_feedback' => $this->manager_feedback,
                'promotion_recommended' => $this->promotion_recommended,
                'status' => 'manager_reviewed',
            ]);
        });

        $this->showReviewModal = false;
        $this->reset(['rating', 'strengths', 'improvements', 'comments', 'manager_feedback', 'promotion_recommended', 'activeReview', 'goalRatings', 'goalComments']);
        \Flux::toast('Manager review submitted.');
    }

    public function render()
    {
        $user = Auth::user();
        $managerEmployee = $user->employee;

        if (!$managerEmployee) {
            return view('livewire.performance.team-reviews', [
                'teamReviews' => collect(),
            ])->layout('layouts.app', ['title' => 'Team Performance Reviews']);
        }

        // Get direct reports' pending reviews
        $teamReviews = PerformanceReview::with(['employee.user', 'employee.jobTitle', 'cycle'])
            ->where('reviewer_id', $managerEmployee->id)
            ->whereIn('status', ['submitted', 'manager_reviewed'])
            ->latest()
            ->get();

        return view('livewire.performance.team-reviews', [
            'teamReviews' => $teamReviews,
        ])->layout('layouts.app', ['title' => 'Team Performance Reviews']);
    }
}
