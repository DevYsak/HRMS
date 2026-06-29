<?php

namespace App\Http\Resources\Api\V1;

use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LeaveRequest
 */
class LeaveSyncResource extends JsonResource
{
    /**
     * Approved leave consumed by the engine to flag leave days.
     * Keyed to the employee by `employee_code` (the device PIN).
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'employee_code' => $this->employee?->employee_code,
            'leave_type' => $this->leaveType?->name,
            'leave_code' => $this->leaveType?->code,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'is_half_day' => (bool) $this->is_half_day,
            'half_day_period' => $this->half_day_period,
            'days' => $this->days,
            'is_paid' => $this->leaveType?->is_paid,
            'status' => $this->status,
        ];
    }
}
