<?php

namespace App\Livewire\Payroll;

use App\Models\Payroll;
use Livewire\Component;

class Overview extends Component
{
    public function render()
    {
        $recentPayrolls = Payroll::latest()->take(5)->get();
        $totalMonthlyPayout = Payroll::where('month', \Illuminate\Support\Carbon::now()->format('F'))
            ->where('year', \Illuminate\Support\Carbon::now()->year)
            ->sum('total_payout');

        return view('livewire.payroll.overview', [
            'recentPayrolls' => $recentPayrolls,
            'totalMonthlyPayout' => $totalMonthlyPayout,
        ])->layout('layouts.app', ['title' => 'Payroll Overview']);
    }
}
