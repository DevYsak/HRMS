<?php

namespace App\Livewire\Employees;

use App\Models\Employee;
use Livewire\Component;

class OrgChart extends Component
{
    public function mount()
    {
        $this->authorize('viewAny', Employee::class);
    }

    public function render()
    {
        // Get all employees grouped by their manager
        // Top level are those with manager_id null
        $employees = Employee::with(['user', 'jobTitle'])->get();
        
        $topLevel = $employees->whereNull('manager_id');
        
        // Group the rest by manager_id
        $groupedByManager = $employees->whereNotNull('manager_id')->groupBy('manager_id');

        return view('livewire.employees.org-chart', [
            'topLevel' => $topLevel,
            'groupedByManager' => $groupedByManager,
        ])->layout('layouts.app', ['title' => 'Organizational Chart']);
    }
}
