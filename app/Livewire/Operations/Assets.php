<?php

namespace App\Livewire\Operations;

use App\Enums\AssetStatus;
use App\Models\Asset;
use App\Models\Employee;
use App\Services\AssetAssignmentService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Assets extends Component
{
    public bool $showCreateModal = false;

    public bool $showAssignModal = false;

    public bool $showReturnModal = false;

    public ?int $selectedAssetId = null;

    public string $name = '';

    public string $type = 'laptop';

    public string $serialNumber = '';

    public string $notes = '';

    public ?int $employeeId = null;

    public string $conditionOnReturn = 'Good';

    public string $returnNotes = '';

    public function openCreateModal(): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->reset(['name', 'type', 'serialNumber', 'notes']);
        $this->showCreateModal = true;
    }

    public function createAsset(AssetAssignmentService $assetService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'name' => ['required', 'string', 'max:191'],
            'type' => ['required', 'string', 'max:80'],
            'serialNumber' => ['nullable', 'string', 'max:191'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $assetService->createAsset([
            'name' => $this->name,
            'type' => $this->type,
            'serial_number' => $this->serialNumber ?: null,
            'notes' => $this->notes ?: null,
        ], Auth::id());

        $this->showCreateModal = false;
        \Flux::toast('Asset created.');
    }

    public function openAssignModal(int $assetId): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->selectedAssetId = $assetId;
        $this->employeeId = null;
        $this->notes = '';
        $this->showAssignModal = true;
    }

    public function assignAsset(AssetAssignmentService $assetService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'selectedAssetId' => ['required', 'exists:assets,id'],
            'employeeId' => ['required', 'exists:employees,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = Asset::findOrFail($this->selectedAssetId);
        if ($asset->status !== AssetStatus::Available) {
            \Flux::toast('Only available assets can be assigned.', variant: 'danger');

            return;
        }

        $employee = Employee::findOrFail($this->employeeId);
        $assetService->assignAsset($asset, $employee, Auth::id(), $this->notes ?: null);

        $this->showAssignModal = false;
        \Flux::toast('Asset assigned and logged.');
    }

    public function openReturnModal(int $assetId): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);
        $this->selectedAssetId = $assetId;
        $this->conditionOnReturn = 'Good';
        $this->returnNotes = '';
        $this->showReturnModal = true;
    }

    public function returnAsset(AssetAssignmentService $assetService): void
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $this->validate([
            'selectedAssetId' => ['required', 'exists:assets,id'],
            'conditionOnReturn' => ['required', 'string', 'max:191'],
            'returnNotes' => ['nullable', 'string', 'max:1000'],
        ]);

        $asset = Asset::findOrFail($this->selectedAssetId);
        if ($asset->status !== AssetStatus::Assigned) {
            \Flux::toast('Only assigned assets can be returned.', variant: 'danger');

            return;
        }

        $assetService->returnAsset($asset, Auth::id(), $this->conditionOnReturn, $this->returnNotes ?: null);

        $this->showReturnModal = false;
        \Flux::toast('Asset returned and equipment log updated.');
    }

    public function render()
    {
        abort_unless(Auth::user()->canManageEmployees(), 403);

        $assets = Asset::with('employee.user')->latest()->get();
        $employees = Employee::with('user')->where('status', 'active')->orderBy('id', 'desc')->get();

        return view('livewire.operations.assets', [
            'assets' => $assets,
            'employees' => $employees,
        ]);
    }
}
