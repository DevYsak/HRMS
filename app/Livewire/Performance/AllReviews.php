<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Services\PerformanceService;
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

    public function viewReview(int $id): void
    {
        abort_unless(auth()->user()->canManageEmployees(), 403);
        $this->viewingReview = PerformanceReview::with(['employee.user', 'employee.jobTitle', 'cycle', 'reviewer.user', 'goals'])->findOrFail($id);
        $this->showViewModal = true;
    }

    public function lockReview(int $id, PerformanceService $performanceService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $review = PerformanceReview::findOrFail($id);
        try {
            $performanceService->lockReview($review);
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Review locked successfully.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $reviews = PerformanceReview::with(['employee.user', 'employee.jobTitle', 'cycle', 'reviewer.user'])
            ->when($this->search, fn ($q) => $q->whereHas('employee.user', fn ($u) => $u->where('name', 'like', "%{$this->search}%")))
            ->when($this->status, fn ($q) => $q->where('status', $this->status))
            ->when($this->cycle_id, fn ($q) => $q->where('review_cycle_id', $this->cycle_id))
            ->latest()
            ->paginate(20);

        return view('livewire.performance.all-reviews', [
            'reviews' => $reviews,
            'cycles' => ReviewCycle::orderByDesc('created_at')->get(),
        ])->layout('layouts.app', ['title' => 'All Performance Reviews']);
    }
}
