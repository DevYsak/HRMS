<?php

namespace App\Services\Performance;

use App\Models\Employee;
use App\Models\PerformanceTimeline;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class TimelineService
{
    public function record(
        Employee $employee,
        string $eventType,
        string $title,
        ?string $description,
        ?Model $reference,
        User $actor,
        bool $visibleToEmployee = true,
    ): PerformanceTimeline {
        return PerformanceTimeline::create([
            'employee_id' => $employee->id,
            'event_type' => $eventType,
            'event_date' => now()->toDateString(),
            'title' => $title,
            'description' => $description,
            'reference_type' => $reference ? class_basename($reference) : null,
            'reference_id' => $reference?->getKey(),
            'created_by' => $actor->id,
            'is_visible_to_employee' => $visibleToEmployee,
        ]);
    }

    public function forEmployee(Employee $employee, bool $employeeView = false): Collection
    {
        return PerformanceTimeline::where('employee_id', $employee->id)
            ->when($employeeView, fn ($q) => $q->where('is_visible_to_employee', true))
            ->with('createdBy')
            ->orderByDesc('event_date')
            ->orderByDesc('id')
            ->get();
    }
}
