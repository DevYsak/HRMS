<?php

namespace App\Livewire\Performance;

use App\Models\ReviewGoal;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Goals extends Component
{
    public bool $showModal = false;
    public ?int $editingId = null;

    // Form fields
    public string $title = '';
    public string $description = '';
    public string $due_date = '';

    public function create(): void
    {
        $this->reset(['title', 'description', 'due_date', 'editingId']);
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $goal = ReviewGoal::findOrFail($id);
        $this->editingId = $id;
        $this->title = $goal->title;
        $this->description = $goal->description ?? '';
        $this->due_date = $goal->due_date?->format('Y-m-d') ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'due_date' => 'nullable|date',
        ]);

        $employee = Auth::user()->employee;

        if ($this->editingId) {
            ReviewGoal::findOrFail($this->editingId)->update([
                'title' => $this->title,
                'description' => $this->description ?: null,
                'due_date' => $this->due_date ?: null,
            ]);
        } else {
            ReviewGoal::create([
                'employee_id' => $employee->id,
                'title' => $this->title,
                'description' => $this->description ?: null,
                'due_date' => $this->due_date ?: null,
            ]);
        }

        $this->showModal = false;
    }

    public function toggleComplete(int $id): void
    {
        $goal = ReviewGoal::findOrFail($id);
        $goal->update([
            'is_completed' => ! $goal->is_completed,
            'completed_at' => $goal->is_completed ? null : now(),
        ]);
    }

    public function delete(int $id): void
    {
        ReviewGoal::findOrFail($id)->delete();
    }

    public function render()
    {
        $employee = Auth::user()->employee;

        $pending = $employee
            ? ReviewGoal::where('employee_id', $employee->id)->where('is_completed', false)->latest()->get()
            : collect();

        $completed = $employee
            ? ReviewGoal::where('employee_id', $employee->id)->where('is_completed', true)->latest()->take(10)->get()
            : collect();

        return view('livewire.performance.goals', [
            'pendingGoals' => $pending,
            'completedGoals' => $completed,
        ])->layout('layouts.app', ['title' => 'My Goals']);
    }
}
