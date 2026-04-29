<?php

namespace App\Livewire\Payroll;

use App\Models\Payroll;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Overview extends Component
{
    public function render()
    {
        abort_unless(Auth::user()->canRunPayroll(), 403);

        $recentPayrolls = Payroll::latest()->take(5)->get();
        $totalMonthlyPayout = Payroll::where('month', Carbon::now()->format('F'))
            ->where('year', Carbon::now()->year)
            ->sum('total_payout');

        return view('livewire.payroll.overview', [
            'recentPayrolls' => $recentPayrolls,
            'totalMonthlyPayout' => $totalMonthlyPayout,
        ])->layout('layouts.app', ['title' => 'Payroll Overview']);
    }
}
