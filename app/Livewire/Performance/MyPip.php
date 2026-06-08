<?php

namespace App\Livewire\Performance;

use App\Models\PipRecord;
use App\Services\Performance\PipService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class MyPip extends Component
{
    public bool $showViewModal = false;

    public ?PipRecord $activeRecord = null;

    public string $employee_comments = '';

    public function viewRecord(int $id): void
    {
        $user = Auth::user();
        if (! $user->employee) {
            abort(403);
        }

        $record = PipRecord::with(['manager', 'hrReviewer', 'goals', 'documents'])->findOrFail($id);

        if ($record->employee_id !== $user->employee->id) {
            abort(403);
        }

        $this->activeRecord = $record;
        $this->employee_comments = $record->employee_comments ?? '';
        $this->showViewModal = true;
    }

    public function submitComments(PipService $service): void
    {
        if (! $this->activeRecord) {
            return;
        }

        $this->validate([
            'employee_comments' => 'required|string|max:1000',
        ]);

        try {
            $service->submitEmployeeComment($this->activeRecord, Auth::user(), $this->employee_comments);
        } catch (\DomainException $e) {
            \Flux::toast($e->getMessage(), variant: 'danger');

            return;
        }

        \Flux::toast('Your comments have been saved.', variant: 'success');
        $this->viewRecord($this->activeRecord->id);
    }

    public function render()
    {
        $user = Auth::user();

        $records = collect();
        if ($user->employee) {
            $records = PipRecord::with(['manager', 'goals'])
                ->where('employee_id', $user->employee->id)
                ->whereNotIn('status', ['draft'])
                ->latest('start_date')
                ->get();
        }

        return view('livewire.performance.my-pip', [
            'records' => $records,
        ])->layout('layouts.app', ['title' => 'My Performance Improvement Plan']);
    }
}
