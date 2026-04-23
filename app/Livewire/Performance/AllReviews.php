<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceReview;
use Livewire\Component;
use Livewire\WithPagination;

class AllReviews extends Component
{
    use WithPagination;

    public string $search = '';
    public string $status = '';
    public string $cycle_id = '';

    public function lockReview(int $id): void
    {
        $review = PerformanceReview::findOrFail($id);
        if ($review->status === 'manager_reviewed') {
            $review->update(['status' => 'locked']);
            \Flux::toast('Review locked successfully.');
        }
    }

    public function render()
    {
        $reviews = PerformanceReview::with(['employee.user', 'employee.jobTitle', 'cycle', 'reviewer.user'])
            ->when($this->search, fn ($q) => $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->cycle_id, fn ($q) => $q->where('review_cycle_id', $this->cycle_id))
            ->latest()
            ->paginate(20);

        return view('livewire.performance.all-reviews', [
            'reviews' => $reviews,
            'cycles' => \App\Models\ReviewCycle::orderByDesc('created_at')->get(),
        ])->layout('layouts.app', ['title' => 'All Performance Reviews']);
    }
}
