<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\WfhRequest;
use Carbon\Carbon;

class WfhService
{
    /**
     * Submit a new work-from-home request.
     *
     * @param  array{start_date: string, end_date: string, reason: string, is_half_day?: bool, half_day_period?: ?string}  $data
     */
    public function submitRequest(Employee $employee, array $data): WfhRequest
    {
        $start = Carbon::parse($data['start_date'])->startOfDay();
        $end = Carbon::parse($data['end_date'])->startOfDay();

        if ($end->lt($start)) {
            throw new \DomainException('The end date cannot be before the start date.');
        }

        $overlapping = $employee->wfhRequests()
            ->whereIn('status', ['pending', 'approved'])
            ->where('start_date', '<=', $end)
            ->where('end_date', '>=', $start)
            ->exists();

        if ($overlapping) {
            throw new \DomainException('You already have a pending or approved work-from-home request that overlaps these dates.');
        }

        return WfhRequest::create([
            'employee_id' => $employee->id,
            'start_date' => $start,
            'end_date' => $end,
            'is_half_day' => $data['is_half_day'] ?? false,
            'half_day_period' => $data['half_day_period'] ?? null,
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);
    }

    /**
     * Whether an approved work-from-home request covers this date.
     *
     * Approval used to change nothing but a status column: an employee with an
     * approved request could still punch in as "office", and an employee with
     * no request at all could pick "wfh" from a dropdown. The approval and the
     * attendance record had no connection, so neither could be trusted.
     */
    public function isApprovedFor(Employee $employee, Carbon $date): bool
    {
        return $employee->wfhRequests()
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }

    public function approve(WfhRequest $request, int $reviewerId, ?string $comment = null): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending work-from-home requests can be approved.');
        }

        $request->update([
            'status' => 'approved',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);
    }

    public function reject(WfhRequest $request, int $reviewerId, string $comment): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending work-from-home requests can be rejected.');
        }

        $request->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);
    }

    public function cancel(WfhRequest $request): void
    {
        if (! $request->isPending()) {
            throw new \DomainException('Only pending work-from-home requests can be cancelled.');
        }

        $request->update(['status' => 'cancelled']);
    }
}
