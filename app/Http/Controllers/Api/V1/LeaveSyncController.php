<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\LeaveSyncResource;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * GET /api/v1/leaves
 *
 * Approved leave requests overlapping a date window, so the Python engine can
 * mark leave days. Filters: ?from=Y-m-d & ?to=Y-m-d (default: current month
 * start → next month end). Only leaves whose employee has a device PIN are
 * returned, since the engine maps by employee_code.
 */
class LeaveSyncController extends Controller
{
    public function __invoke(Request $request): AnonymousResourceCollection
    {
        $from = $request->date('from') ?? now()->startOfMonth();
        $to = $request->date('to') ?? now()->addMonth()->endOfMonth();

        $leaves = LeaveRequest::query()
            ->with(['employee', 'leaveType'])
            ->where('status', 'approved')
            ->whereHas('employee', fn ($query) => $query->whereNotNull('employee_code'))
            // Overlap: leave spans any part of the [from, to] window.
            ->whereDate('start_date', '<=', $to)
            ->whereDate('end_date', '>=', $from)
            ->orderBy('start_date')
            ->get();

        return LeaveSyncResource::collection($leaves);
    }
}
