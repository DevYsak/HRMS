<?php

namespace App\Livewire\Performance;

use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Services\Performance\ReviewWorkflowService;
use App\Services\PerformanceService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Cycle management for the template-driven performance flow — replaces the
 * legacy ReviewCycles screen (retired in the v4 ReviewCycle→PerformanceCycle
 * cutover). Activation delegates to ReviewWorkflowService so reviews, KPI
 * rows and score shells are created consistently.
 */
class PerformanceCycles extends Component
{
    public bool $showModal = false;

    public ?int $editingId = null;

    public ?int $templateId = null;

    public string $name = '';

    public string $cycleType = 'quarterly';

    public string $startDate = '';

    public string $endDate = '';

    public string $selfReviewDeadline = '';

    public string $managerReviewDeadline = '';

    public string $hrReviewDeadline = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
    }

    public function create(): void
    {
        $this->reset(['editingId', 'templateId', 'name', 'startDate', 'endDate', 'selfReviewDeadline', 'managerReviewDeadline', 'hrReviewDeadline']);
        $this->cycleType = 'quarterly';
        $this->showModal = true;
    }

    public function edit(int $id): void
    {
        $cycle = PerformanceCycle::findOrFail($id);

        if ($cycle->status !== 'draft') {
            \Flux::toast('Only draft cycles can be edited.', variant: 'warning');

            return;
        }

        $this->editingId = $cycle->id;
        $this->templateId = $cycle->template_id;
        $this->name = $cycle->name;
        $this->cycleType = $cycle->cycle_type;
        $this->startDate = $cycle->start_date->format('Y-m-d');
        $this->endDate = $cycle->end_date->format('Y-m-d');
        $this->selfReviewDeadline = $cycle->self_review_deadline?->format('Y-m-d') ?? '';
        $this->managerReviewDeadline = $cycle->manager_review_deadline?->format('Y-m-d') ?? '';
        $this->hrReviewDeadline = $cycle->hr_review_deadline?->format('Y-m-d') ?? '';
        $this->showModal = true;
    }

    public function save(PerformanceService $performance): void
    {
        $this->validate([
            'templateId' => ['required', Rule::exists('performance_templates', 'id')],
            'name' => 'required|string|max:255',
            'cycleType' => 'required|in:monthly,quarterly,half_yearly,annual,custom',
            'startDate' => 'required|date',
            'endDate' => 'required|date|after:startDate',
            'selfReviewDeadline' => 'nullable|date',
            'managerReviewDeadline' => 'nullable|date',
            'hrReviewDeadline' => 'nullable|date',
        ]);

        try {
            $performance->validateConexusQuarterWindow($this->name, $this->startDate, $this->endDate);
        } catch (\DomainException $exception) {
            $this->addError('startDate', $exception->getMessage());

            return;
        }

        $data = [
            'template_id' => $this->templateId,
            'name' => $this->name,
            'cycle_type' => $this->cycleType,
            'start_date' => $this->startDate,
            'end_date' => $this->endDate,
            'self_review_deadline' => $this->selfReviewDeadline ?: null,
            'manager_review_deadline' => $this->managerReviewDeadline ?: null,
            'hr_review_deadline' => $this->hrReviewDeadline ?: null,
        ];

        if ($this->editingId) {
            $cycle = PerformanceCycle::findOrFail($this->editingId);

            if ($cycle->status !== 'draft') {
                \Flux::toast('Only draft cycles can be edited.', variant: 'warning');

                return;
            }

            $cycle->update($data);
            \Flux::toast('Cycle updated.');
        } else {
            PerformanceCycle::create($data + ['status' => 'draft', 'created_by' => Auth::id()]);
            \Flux::toast('Cycle created as draft. Activate it to assign reviews.');
        }

        $this->showModal = false;
    }

    public function activate(int $id, ReviewWorkflowService $workflow): void
    {
        $cycle = PerformanceCycle::findOrFail($id);

        try {
            $workflow->activateCycle($cycle, Auth::user());
        } catch (\DomainException $exception) {
            \Flux::toast($exception->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Cycle activated — reviews and KPIs assigned.');
    }

    public function complete(int $id): void
    {
        $cycle = PerformanceCycle::findOrFail($id);

        if (in_array($cycle->status, ['draft', 'completed', 'locked'], true)) {
            \Flux::toast('Only running cycles can be completed.', variant: 'warning');

            return;
        }

        $cycle->update(['status' => 'completed']);
        \Flux::toast('Cycle marked as completed.');
    }

    public function render()
    {
        $cycles = PerformanceCycle::with('template')
            ->withCount('reviews')
            ->orderByDesc('start_date')
            ->get();

        return view('livewire.performance.performance-cycles', [
            'cycles' => $cycles,
            'templates' => PerformanceTemplate::where('is_active', true)->orderBy('name')->get(),
        ])->layout('layouts.app', ['title' => 'Performance Cycles']);
    }
}
