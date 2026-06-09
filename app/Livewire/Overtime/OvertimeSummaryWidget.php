<?php

namespace App\Livewire\Overtime;

use App\Models\OtRequest;
use Illuminate\Support\Carbon;
use Livewire\Component;

class OvertimeSummaryWidget extends Component
{
    public function render()
    {
        $today = Carbon::today();
        $weekStart = $today->copy()->startOfWeek();
        $monthStart = $today->copy()->startOfMonth();

        return view('livewire.overtime.overtime-summary-widget', [
            'otToday' => OtRequest::whereDate('work_date', $today)->count(),
            'otWeek' => OtRequest::where('work_date', '>=', $weekStart)->count(),
            'otMonth' => OtRequest::where('work_date', '>=', $monthStart)->count(),
            'pendingCount' => OtRequest::where('status', 'pending')->count(),
            'approvedCount' => OtRequest::where('status', 'approved')->count(),
            'nexflowSynced' => OtRequest::where('source', 'nexflow')
                ->where('work_date', '>=', $monthStart)
                ->count(),
        ]);
    }
}
