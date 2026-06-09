<?php

namespace App\Services;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Employee;
use App\Models\EquipmentLog;
use App\Models\OnboardingTask;
use Illuminate\Support\Facades\DB;

class AssetAssignmentService
{
    public function createAsset(array $data, int $handledBy): Asset
    {
        return DB::transaction(function () use ($data) {
            $asset = Asset::create([
                'name' => $data['name'],
                'type' => $data['type'],
                'serial_number' => $data['serial_number'] ?? null,
                'notes' => $data['notes'] ?? null,
                'status' => AssetStatus::Available,
                'employee_id' => null,
                'assigned_date' => null,
                'returned_date' => null,
                'condition_on_return' => null,
            ]);

            return $asset;
        });
    }

    public function assignAsset(Asset $asset, Employee $employee, int $handledBy, ?string $notes = null): Asset
    {
        return DB::transaction(function () use ($asset, $employee, $handledBy, $notes) {
            $asset->update([
                'employee_id' => $employee->id,
                'status' => AssetStatus::Assigned,
                'assigned_date' => now(),
                'returned_date' => null,
                'condition_on_return' => null,
                'notes' => $notes ?: $asset->notes,
            ]);

            EquipmentLog::create([
                'employee_id' => $employee->id,
                'item_name' => $asset->name,
                'serial_number' => $asset->serial_number,
                'description' => $asset->type,
                'action' => 'issued',
                'action_date' => now()->toDateString(),
                'handled_by' => $handledBy,
                'notes' => $notes,
            ]);

            app(OnboardingService::class)->autoComplete($employee, 'asset_assign', $handledBy);

            return $asset->fresh(['employee.user']);
        });
    }

    public function returnAsset(Asset $asset, int $handledBy, ?string $condition = null, ?string $notes = null): Asset
    {
        return DB::transaction(function () use ($asset, $handledBy, $condition, $notes) {
            $employeeId = $asset->employee_id;

            $asset->update([
                'status' => AssetStatus::Available,
                'returned_date' => now(),
                'condition_on_return' => $condition ?: 'Good',
                'employee_id' => null,
                'notes' => $notes ?: $asset->notes,
            ]);

            if ($employeeId) {
                EquipmentLog::create([
                    'employee_id' => $employeeId,
                    'item_name' => $asset->name,
                    'serial_number' => $asset->serial_number,
                    'description' => $asset->type,
                    'action' => 'returned',
                    'action_date' => now()->toDateString(),
                    'handled_by' => $handledBy,
                    'notes' => trim(($condition ? "Condition: {$condition}. " : '').($notes ?? '')),
                ]);

                $this->syncOffboardingEquipmentChecklist($employeeId, $handledBy);
            }

            return $asset->fresh();
        });
    }

    protected function syncOffboardingEquipmentChecklist(int $employeeId, int $completedBy): void
    {
        $hasAssignedAssets = Asset::where('employee_id', $employeeId)
            ->where('status', AssetStatus::Assigned->value)
            ->exists();

        if ($hasAssignedAssets) {
            return;
        }

        OnboardingTask::where('employee_id', $employeeId)
            ->where('phase', 'offboarding')
            ->where(function ($query) {
                $query->where('category', 'it_setup')
                    ->orWhere('category', 'it')
                    ->orWhere('title', 'like', '%asset%')
                    ->orWhere('title', 'like', '%equipment%')
                    ->orWhere('title', 'like', '%hardware%');
            })
            ->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $completedBy,
            ]);
    }
}
