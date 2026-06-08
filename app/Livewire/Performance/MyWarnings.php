<?php

namespace App\Livewire\Performance;

use App\Models\WarningLetter;
use App\Services\Performance\WarningService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyWarnings extends Component
{
    public bool $showViewModal = false;

    public ?WarningLetter $activeWarning = null;

    public string $acknowledgementText = '';

    public function viewWarning(int $id)
    {
        $user = Auth::user();
        if (! $user->employee) {
            abort(403);
        }

        $warning = WarningLetter::with(['issuedBy', 'closedBy', 'acknowledgements'])->findOrFail($id);

        if ($warning->employee_id !== $user->employee->id) {
            abort(403);
        }

        $this->activeWarning = $warning;
        $this->acknowledgementText = '';
        $this->showViewModal = true;
    }

    public function acknowledgeWarning(WarningService $warningService)
    {
        if (! $this->activeWarning) {
            return;
        }

        $this->validate([
            'acknowledgementText' => 'nullable|string|max:500',
        ]);

        try {
            $warningService->acknowledge($this->activeWarning, Auth::user(), $this->acknowledgementText);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        $this->showViewModal = false;
        \Flux::toast('Warning acknowledged.', variant: 'success');
    }

    public function render()
    {
        $user = Auth::user();

        $warnings = collect();
        if ($user->employee) {
            $warnings = WarningLetter::with(['issuedBy'])
                ->where('employee_id', $user->employee->id)
                ->whereIn('status', ['issued', 'acknowledged', 'closed'])
                ->latest()
                ->get();
        }

        return view('livewire.performance.my-warnings', [
            'warnings' => $warnings,
        ])->layout('layouts.app', ['title' => 'My Warnings']);
    }
}
