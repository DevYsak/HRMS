<?php

namespace App\Livewire\Operations;

use App\Models\ExpenseClaim;
use Livewire\Component;

class Expenses extends Component
{
    public function render()
    {
        $expenses = ExpenseClaim::with('employee.user', 'approver')->latest()->get();
        
        return view('livewire.operations.expenses', [
            'expenses' => $expenses,
        ]);
    }
}
