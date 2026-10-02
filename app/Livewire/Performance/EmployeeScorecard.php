<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceReview;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class EmployeeScorecard extends Component
{
    public PerformanceReview $review;

    public function mount(int $id)
    {
        $this->review = PerformanceReview::with([
            'employee.user',
            'employee.jobTitle',
            'employee.department',
            'performanceCycle',
            'reviewer.user',
            'componentScores.component.category',
        ])->findOrFail($id);

        // Check authorization (Employee, Manager, or HR)
        // A user with no employee record must not match a null id.
        $user = Auth::user();
        $ownEmployeeId = $user->employee?->id;
        $isOwn = $ownEmployeeId !== null && (int) $this->review->employee_id === (int) $ownEmployeeId;
        $isReviewer = $ownEmployeeId !== null && (int) $this->review->reviewer_id === (int) $ownEmployeeId;
        $inReach = $this->review->employee !== null
            && ($user->canManageEmployees() || $user->canReviewPerformance())
            && $user->coversEmployee($this->review->employee);

        if (! $isOwn && ! $isReviewer && ! $inReach) {
            abort(403);
        }

        // Must be locked
        if ($this->review->status !== 'locked') {
            abort(404, 'Scorecard is not yet available.');
        }
    }

    public function render()
    {
        // Group component scores by category for display
        $categories = collect();
        foreach ($this->review->componentScores as $score) {
            $cat = $score->component->category;
            $catId = $cat ? $cat->id : 0;
            $catName = $cat ? $cat->name : 'Uncategorized';

            if (! $categories->has($catId)) {
                $categories->put($catId, [
                    'name' => $catName,
                    'scores' => collect(),
                    'total_weight' => 0,
                    'earned_weight' => 0,
                ]);
            }

            $catData = $categories->get($catId);
            $catData['scores']->push($score);
            $catData['total_weight'] += $score->component->weight_percent;
            $catData['earned_weight'] += $score->weighted_score ?? 0;

            $categories->put($catId, $catData);
        }

        return view('livewire.performance.employee-scorecard', [
            'categories' => $categories,
        ])->layout('layouts.app', ['title' => 'Employee Scorecard']);
    }
}
