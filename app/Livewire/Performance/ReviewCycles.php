<?php

namespace App\Livewire\Performance;

use App\Models\Employee;
use App\Models\PerformanceReview;
use App\Models\ReviewCycle;
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
        $this->reset(['name', 'start_date', 'end_date', 'status', 'description', 'editingId']);
        $this->status = 'draft';
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $cycle = ReviewCycle::findOrFail($id);
        $this->editingId = $id;
        $this->name = $cycle->name;
        $this->start_date = $cycle->start_date->format('Y-m-d');
        $this->end_date = $cycle->end_date->format('Y-m-d');
        $this->status = $cycle->status;
        $this->description = $cycle->description ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'status' => 'required|in:draft,active,closed',
        ]);

        $data = [
            'name' => $this->name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'status' => $this->status,
            'description' => $this->description ?: null,
        ];

        if ($this->editingId) {
            ReviewCycle::findOrFail($this->editingId)->update($data);
            session()->flash('status', 'Review cycle updated.');
        } else {
            $cycle = ReviewCycle::create($data);
            // Auto-create self & manager reviews for all active employees
            $this->generateReviewsForCycle($cycle);
            session()->flash('status', 'Review cycle created and reviews assigned to employees.');
        }

        $this->showModal = false;
    }

    protected function generateReviewsForCycle(ReviewCycle $cycle): void
    {
        $employees = Employee::whereIn('status', [
            \App\Enums\EmployeeStatus::Active,
            \App\Enums\EmployeeStatus::Onboarding,
            \App\Enums\EmployeeStatus::Probation,
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
        ReviewCycle::findOrFail($id)->update(['status' => 'active']);
        session()->flash('status', 'Cycle activated.');
    }

    public function close(int $id): void
    {
        ReviewCycle::findOrFail($id)->update(['status' => 'closed']);
        session()->flash('status', 'Cycle closed.');
    }

    public function render()
    {
        $cycles = ReviewCycle::withCount('reviews')->latest()->get();

        return view('livewire.performance.review-cycles', [
            'cycles' => $cycles,
        ])->layout('layouts.app', ['title' => 'Review Cycles']);
    }
}
