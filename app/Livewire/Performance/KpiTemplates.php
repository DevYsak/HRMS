<?php

namespace App\Livewire\Performance;

use App\Models\Department;
use App\Models\PerformanceCycle;
use App\Models\PerformanceTemplate;
use App\Services\Performance\PerformanceTemplateService;
use App\Services\Performance\ReviewWorkflowService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Livewire\Component;

class KpiTemplates extends Component
{
    // ── Template list ────────────────────────────────────────────────────────
    public ?int $selectedTemplateId = null;

    public bool $showForm = false;

    // ── Template form ────────────────────────────────────────────────────────
    public string $name = '';

    public string $code = '';

    public string $description = '';

    public string $applies_to_type = 'global';

    public ?int $applies_to_id = null;

    public string $cycle_type = 'quarterly';

    public bool $is_active = true;

    // ── Inline categories & components ───────────────────────────────────────
    /** @var array<int, array{id: ?int, name: string, code: string, color: string, components: array}> */
    public array $categories = [];

    // ── Cycle launcher ───────────────────────────────────────────────────────
    public bool $showLaunchModal = false;

    public string $cycleName = '';

    public string $cycleStartDate = '';

    public string $cycleEndDate = '';

    public string $cycleSelfDeadline = '';

    public string $cycleManagerDeadline = '';

    public string $cycleHrDeadline = '';

    public ?int $launchTemplateId = null;

    // ── Delete confirm ───────────────────────────────────────────────────────
    public bool $showDeleteConfirm = false;

    public ?int $deletingId = null;

    public function selectTemplate(int $id): void
    {
        $this->selectedTemplateId = $id;
        $this->loadTemplate($id);
        $this->resetErrorBag();
    }

    public function newTemplate(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->showForm = true;
        $this->selectedTemplateId = null;
        $this->reset(['name', 'code', 'description', 'applies_to_type', 'applies_to_id', 'cycle_type']);
        $this->is_active = true;
        $this->categories = [];
        $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->selectedTemplateId = null;
        $this->reset(['name', 'code', 'description', 'applies_to_type', 'applies_to_id', 'cycle_type']);
        $this->is_active = true;
        $this->categories = [];
        $this->resetErrorBag();
    }

    private function loadTemplate(int $id): void
    {
        $this->showForm = true;
        $template = PerformanceTemplate::with(['categories.components'])->findOrFail($id);

        $this->name = $template->name;
        $this->code = $template->code;
        $this->description = $template->description ?? '';
        $this->applies_to_type = $template->applies_to_type;
        $this->applies_to_id = $template->applies_to_id;
        $this->cycle_type = $template->cycle_type;
        $this->is_active = $template->is_active;

        $this->categories = $template->categories->map(fn ($cat) => [
            'id' => $cat->id,
            'name' => $cat->name,
            'code' => $cat->code ?? '',
            'color' => $cat->color,
            'components' => $cat->components->map(fn ($comp) => [
                'id' => $comp->id,
                'name' => $comp->name,
                'description' => $comp->description ?? '',
                'scoring_type' => $comp->scoring_type,
                'weight_percent' => $comp->weight_percent,
                'max_score' => $comp->max_score,
            ])->toArray(),
        ])->toArray();
    }

    public function addCategory(): void
    {
        $this->categories[] = [
            'id' => null,
            'name' => '',
            'code' => '',
            'color' => '#6366F1',
            'components' => [],
        ];
    }

    public function removeCategory(int $index): void
    {
        array_splice($this->categories, $index, 1);
    }

    public function addComponent(int $categoryIndex): void
    {
        $this->categories[$categoryIndex]['components'][] = [
            'id' => null,
            'name' => '',
            'description' => '',
            'scoring_type' => 'manual',
            'weight_percent' => 0,
            'max_score' => 100,
        ];
    }

    public function removeComponent(int $categoryIndex, int $componentIndex): void
    {
        array_splice($this->categories[$categoryIndex]['components'], $componentIndex, 1);
    }

    public function totalWeight(): float
    {
        $total = 0.0;
        foreach ($this->categories as $cat) {
            foreach ($cat['components'] ?? [] as $comp) {
                $total += (float) ($comp['weight_percent'] ?? 0);
            }
        }

        return round($total, 2);
    }

    public function save(PerformanceTemplateService $service): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        Log::info('KPI template save() entered — server-side categories snapshot.', [
            'user_id' => Auth::id(),
            'template_id' => $this->selectedTemplateId,
            'category_count' => count($this->categories),
            'categories' => $this->categories,
        ]);

        $this->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('performance_templates', 'code')->ignore($this->selectedTemplateId)->whereNull('deleted_at')],
            'applies_to_type' => 'required|in:role,department,designation,employment_type,shift,branch,global',
            'cycle_type' => 'required|in:monthly,quarterly,half_yearly,annual,custom',
            'categories' => 'array',
            'categories.*.name' => 'required|string|max:100',
            'categories.*.components' => 'array',
            'categories.*.components.*.name' => 'required|string|max:100',
            'categories.*.components.*.weight_percent' => 'required|numeric|min:0|max:100',
            'categories.*.components.*.scoring_type' => 'required|in:manual,auto_attendance,auto_punctuality,auto_leave,auto_productivity,auto_shift_compliance',
        ]);

        // Validate total weight
        $total = $this->totalWeight();
        if (count($this->categories) > 0 && abs($total - 100.0) > 0.01) {
            Log::info('KPI template save blocked: component weights do not total 100%.', [
                'user_id' => Auth::id(),
                'template_id' => $this->selectedTemplateId,
                'total_weight' => $total,
                'categories' => $this->categories,
            ]);

            $this->addError('categories', "Component weights must total 100%. Current total: {$total}%.");

            return;
        }

        $isNew = $this->selectedTemplateId === null;

        try {
            $template = $service->save(
                array_merge(
                    [
                        'name' => $this->name,
                        'code' => $this->code,
                        'description' => $this->description ?: null,
                        'applies_to_type' => $this->applies_to_type,
                        'applies_to_id' => $this->applies_to_id ?: null,
                        'cycle_type' => $this->cycle_type,
                        'is_active' => $this->is_active,
                    ],
                    $this->selectedTemplateId ? ['id' => $this->selectedTemplateId] : [],
                ),
                $this->categories,
                Auth::user(),
            );

            $this->selectedTemplateId = $template->id;
            $this->loadTemplate($template->id);
        } catch (\DomainException $e) {
            Log::error('KPI template save failed: domain rule violated.', [
                'user_id' => Auth::id(),
                'template_id' => $this->selectedTemplateId,
                'categories' => $this->categories,
                'message' => $e->getMessage(),
            ]);

            $this->addError('categories', $e->getMessage());

            return;
        } catch (\Throwable $e) {
            Log::error('KPI template save failed: unexpected exception.', [
                'user_id' => Auth::id(),
                'template_id' => $this->selectedTemplateId,
                'categories' => $this->categories,
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            \Flux::toast('Could not save template: '.$e->getMessage(), variant: 'danger');

            return;
        }

        Log::info('KPI template saved successfully.', [
            'user_id' => Auth::id(),
            'template_id' => $template->id,
            'is_new' => $isNew,
            'category_count' => $template->categories()->count(),
            'component_count' => $template->components()->count(),
        ]);

        \Flux::toast(
            $isNew ? 'Template created.' : 'Template updated.',
            variant: 'success'
        );
    }

    public function confirmDelete(int $id): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);
        $this->deletingId = $id;
        $this->showDeleteConfirm = true;
    }

    public function deleteTemplate(): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $template = PerformanceTemplate::findOrFail($this->deletingId);

        if ($template->cycles()->exists()) {
            $this->addError('delete', 'Cannot delete a template with existing performance cycles.');
            $this->showDeleteConfirm = false;

            return;
        }

        $template->delete();
        $this->showDeleteConfirm = false;
        $this->deletingId = null;

        if ($this->selectedTemplateId === $template->id) {
            $this->newTemplate();
        }

        \Flux::toast('Template deleted.', variant: 'success');
    }

    // ── Cycle launcher ───────────────────────────────────────────────────────

    public function openLaunchCycle(int $templateId): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $template = PerformanceTemplate::findOrFail($templateId);

        $this->launchTemplateId = $templateId;
        $this->cycleName = '';
        $this->cycleStartDate = now()->startOfMonth()->toDateString();
        $this->cycleEndDate = now()->endOfMonth()->toDateString();
        $this->cycleSelfDeadline = '';
        $this->cycleManagerDeadline = '';
        $this->cycleHrDeadline = '';
        $this->showLaunchModal = true;
    }

    public function launchCycle(ReviewWorkflowService $workflowService): void
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $this->validate([
            'cycleName' => 'required|string|max:255',
            'cycleStartDate' => 'required|date',
            'cycleEndDate' => 'required|date|after:cycleStartDate',
            'cycleSelfDeadline' => 'nullable|date',
            'cycleManagerDeadline' => 'nullable|date',
            'cycleHrDeadline' => 'nullable|date',
        ]);

        $template = PerformanceTemplate::findOrFail($this->launchTemplateId);

        try {
            $service = app(PerformanceTemplateService::class);
            $service->assertWeightageValid($template);
        } catch (\DomainException $e) {
            $this->addError('cycleName', $e->getMessage());

            return;
        }

        $cycle = PerformanceCycle::create([
            'name' => $this->cycleName,
            'template_id' => $this->launchTemplateId,
            'cycle_type' => $template->cycle_type,
            'start_date' => $this->cycleStartDate,
            'end_date' => $this->cycleEndDate,
            'self_review_deadline' => $this->cycleSelfDeadline ?: null,
            'manager_review_deadline' => $this->cycleManagerDeadline ?: null,
            'hr_review_deadline' => $this->cycleHrDeadline ?: null,
            'status' => 'draft',
            'created_by' => Auth::id(),
        ]);

        try {
            $workflowService->activateCycle($cycle, Auth::user());
        } catch (\DomainException $e) {
            $cycle->delete();
            $this->addError('cycleName', $e->getMessage());

            return;
        }

        $this->showLaunchModal = false;
        \Flux::toast("Cycle '{$cycle->name}' launched and KPIs assigned to employees.", variant: 'success');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageSettings(), 403);

        $templates = PerformanceTemplate::withCount(['categories', 'components', 'cycles'])
            ->latest()
            ->get();

        $departments = Department::orderBy('name')->get();

        return view('livewire.performance.kpi-templates', [
            'templates' => $templates,
            'departments' => $departments,
            'totalWeight' => $this->totalWeight(),
        ])->layout('layouts.app', ['title' => 'KPI Templates']);
    }
}
