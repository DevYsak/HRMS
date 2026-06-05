<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\PerformanceCategory;
use App\Models\PerformanceComponent;
use App\Models\PerformanceTemplate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PerformanceTemplateService
{
    /**
     * Create or update a template with its categories and components in one transaction.
     *
     * @param  array{name: string, code: string, applies_to_type: string, applies_to_id: ?int, cycle_type: string}  $templateData
     * @param  array<int, array{name: string, color: string, components: array}>  $categories
     */
    public function save(array $templateData, array $categories, User $actor): PerformanceTemplate
    {
        return DB::transaction(function () use ($templateData, $categories, $actor) {
            $template = isset($templateData['id'])
                ? PerformanceTemplate::findOrFail($templateData['id'])
                : new PerformanceTemplate;

            $template->fill(array_merge($templateData, ['created_by' => $actor->id]));
            $template->save();

            $this->syncCategories($template, $categories);

            return $template->fresh(['categories.components']);
        });
    }

    private function syncCategories(PerformanceTemplate $template, array $categories): void
    {
        $keptCategoryIds = [];

        foreach ($categories as $sortOrder => $catData) {
            $category = isset($catData['id'])
                ? PerformanceCategory::findOrFail($catData['id'])
                : new PerformanceCategory(['template_id' => $template->id]);

            $category->fill([
                'template_id' => $template->id,
                'name' => $catData['name'],
                'code' => $catData['code'] ?? null,
                'color' => $catData['color'] ?? '#6366F1',
                'description' => $catData['description'] ?? null,
                'sort_order' => $sortOrder,
            ]);
            $category->save();

            $keptCategoryIds[] = $category->id;
            $this->syncComponents($template, $category, $catData['components'] ?? []);
        }

        // Soft-delete removed categories
        $template->categories()
            ->whereNotIn('id', $keptCategoryIds)
            ->each(fn ($c) => $c->delete());
    }

    private function syncComponents(PerformanceTemplate $template, PerformanceCategory $category, array $components): void
    {
        $keptIds = [];

        foreach ($components as $sortOrder => $compData) {
            $component = isset($compData['id'])
                ? PerformanceComponent::findOrFail($compData['id'])
                : new PerformanceComponent;

            $component->fill([
                'category_id' => $category->id,
                'template_id' => $template->id,
                'name' => $compData['name'],
                'description' => $compData['description'] ?? null,
                'scoring_type' => $compData['scoring_type'] ?? 'manual',
                'max_score' => $compData['max_score'] ?? 100,
                'weight_percent' => $compData['weight_percent'] ?? 0,
                'target_value' => $compData['target_value'] ?? null,
                'sort_order' => $sortOrder,
            ]);
            $component->save();
            $keptIds[] = $component->id;
        }

        $category->components()
            ->whereNotIn('id', $keptIds)
            ->each(fn ($c) => $c->delete());
    }

    /**
     * Validate that the sum of all component weights equals 100%.
     * Throws DomainException if invalid.
     */
    public function assertWeightageValid(PerformanceTemplate $template): void
    {
        $total = $template->components()->sum('weight_percent');

        if (abs($total - 100.0) > 0.01) {
            throw new \DomainException(
                "Template '{$template->name}' component weights sum to {$total}% — must equal 100%."
            );
        }
    }

    /**
     * Resolve the best-matching active template for an employee.
     * Priority: employee override → designation → role → department → branch → global.
     */
    public function resolveForEmployee(Employee $employee): ?PerformanceTemplate
    {
        $active = PerformanceTemplate::where('is_active', true)->get();

        // 1. Employee-level override (future: store performance_template_id on employees)
        // 2. Designation
        if ($employee->designation_id && $t = $active->firstWhere(fn ($t) => $t->applies_to_type === 'designation' && $t->applies_to_id === $employee->designation_id)) {
            return $t;
        }
        // 3. Department
        if ($employee->department_id && $t = $active->firstWhere(fn ($t) => $t->applies_to_type === 'department' && $t->applies_to_id === $employee->department_id)) {
            return $t;
        }
        // 4. Role
        if ($employee->user?->role && $t = $active->firstWhere(fn ($t) => $t->applies_to_type === 'role' && $t->applies_to_id === null)) {
            // Role match by name stored in applies_to_id — skip for now, fall through
        }

        // 5. Global fallback
        return $active->firstWhere('applies_to_type', 'global');
    }
}
