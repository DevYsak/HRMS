<?php

namespace App\Livewire\Performance;

use App\Enums\EmployeeStatus;
use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
use App\Models\ReviewGoal;
use App\Services\PerformanceService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ReviewCycles extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    // Form fields
    public string $name = '';

    public string $start_date = '';

    public string $end_date = '';

    public string $status = 'draft';

    public string $description = '';

    public function create(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->reset(['name', 'start_date', 'end_date', 'status', 'description', 'editingId']);
        $this->status = 'draft';
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $cycle = ReviewCycle::findOrFail($id);
        $this->editingId = $id;
        $this->name = $cycle->name;
        $this->start_date = $cycle->start_date->format('Y-m-d');
        $this->end_date = $cycle->end_date->format('Y-m-d');
        $this->status = $cycle->status;
        $this->description = $cycle->description ?? '';
        $this->showModal = true;
    }

    public function save(PerformanceService $performanceService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => 'required|in:draft,active,closed',
        ]);

        try {
            $performanceService->validateConexusQuarterWindow($this->name, $this->start_date, $this->end_date);
        } catch (\DomainException $exception) {
            $this->addError('start_date', $exception->getMessage());

            return;
        }

        $data = [
            'name' => $this->name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'description' => $this->description ?: null,
        ];

        if ($this->editingId) {
            ReviewCycle::findOrFail($this->editingId)->update($data);
            \Flux::toast('Review cycle updated.');
        } else {
            $cycle = ReviewCycle::create($data);
            // Auto-create self & manager reviews for all active employees
            $this->generateReviewsForCycle($cycle);
            \Flux::toast('Review cycle created and reviews assigned to employees.');
        }

        $this->showModal = false;
    }

    protected function generateReviewsForCycle(ReviewCycle $cycle): void
    {
        $employees = Employee::whereIn('status', [
            EmployeeStatus::Active,
            EmployeeStatus::Onboarding,
            EmployeeStatus::Probation,
        ])->get();

        foreach ($employees as $employee) {
            $managerEmployee = null;
            if ($employee->manager_id) {
                $managerEmployee = Employee::where('user_id', $employee->manager_id)->first();
            }

            $review = PerformanceReview::create([
                'review_cycle_id' => $cycle->id,
                'employee_id' => $employee->id,
                'reviewer_id' => $managerEmployee?->id,
                'status' => 'draft',
            ]);

            // Link active goals that aren't already linked to a review
            ReviewGoal::where('employee_id', $employee->id)
                ->whereNull('performance_review_id')
                ->update(['performance_review_id' => $review->id]);
        }
    }

    public function activate(int $id): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        ReviewCycle::findOrFail($id)->update(['status' => 'active']);
        \Flux::toast('Cycle activated.');
    }

    public function close(int $id): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        ReviewCycle::findOrFail($id)->update(['status' => 'closed']);
        \Flux::toast('Cycle closed.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $cycles = ReviewCycle::withCount('reviews')->latest()->get();

        return view('livewire.performance.review-cycles', [
            'cycles' => $cycles,
        ])->layout('layouts.app', ['title' => 'Review Cycles']);
    }
}
