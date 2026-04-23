<?php

namespace App\Livewire\Operations;

use App\Models\Asset;
use Livewire\Component;

class Assets extends Component
{
    public function render()
    {
        $assets = Asset::with('employee.user')->latest()->get();
        
        return view('livewire.operations.assets', [
            'assets' => $assets,
        ]);
    }
}
