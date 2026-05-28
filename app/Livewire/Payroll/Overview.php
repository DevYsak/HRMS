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

        $now = Carbon::now();
        $lastMonth = $now->copy()->subMonth();

        $recentPayrolls = Payroll::latest('created_at')->take(10)->get();

        $thisMonthPayout = Payroll::where('month', $now->format('F'))
            ->where('year', $now->year)
            ->sum('total_payout');

        $lastMonthPayout = Payroll::where('month', $lastMonth->format('F'))
            ->where('year', $lastMonth->year)
            ->sum('total_payout');

        $ytdPayout = Payroll::where('year', $now->year)->sum('total_payout');

        $statusCounts = Payroll::selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('livewire.payroll.overview', [
            'recentPayrolls' => $recentPayrolls,
            'totalMonthlyPayout' => $thisMonthPayout,
            'lastMonthPayout' => $lastMonthPayout,
            'ytdPayout' => $ytdPayout,
            'statusCounts' => $statusCounts,
        ])->layout('layouts.app', ['title' => 'Payroll Overview']);
    }
}
