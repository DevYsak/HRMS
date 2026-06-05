<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OtWindow;
use App\Models\OvertimeRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OvertimeService
{
    public const STANDARD_HOURS = 9.0;

    public const RATE_PER_HOUR = 100.0;

    /**
     * Create a pre-approval OT request.
     * REQ-06: Only accepted when an active company OT window covers the work date.
     *
     * @param  array{work_date: string, start_time: string, end_time: string, reason: string, attendance_id?: int}  $data
     */
    public function submitRequest(Employee $employee, array $data): OtRequest
    {
        $workDate = Carbon::parse($data['work_date']);

        if (! OtWindow::isOpenFor($workDate)) {
            throw new \DomainException(
                'Overtime requests are not accepted at this time. '
                .'Please wait for a company-approved OT window to be opened by HR or a Director.'
            );
        }

        $start = Carbon::parse("{$data['work_date']} {$data['start_time']}");
        $end = Carbon::parse("{$data['work_date']} {$data['end_time']}");
        $hours = max(0, $end->floatDiffInHours($start));

        return OtRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $data['attendance_id'] ?? null,
            'work_date' => $data['work_date'],
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'requested_hours' => round($hours, 2),
            'reason' => $data['reason'],
            'status' => 'pending',
        ]);
    }

    /**
     * Approve an OT request and materialise the OvertimeRecord.
     */
    public function approve(OtRequest $request, int $reviewerId, ?string $comment = null): OvertimeRecord
    {
        return DB::transaction(function () use ($request, $reviewerId, $comment) {
            if ($request->status !== 'pending') {
                throw new \DomainException('Only pending OT requests can be approved.');
            }

            $request->update([
                'status' => 'approved',
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
                'reviewed_at' => now(),
            ]);

            return $this->createOvertimeRecordFromApprovedRequest($request->fresh(['attendance']));
        });
    }

    /**
     * Reject an OT request.
     */
    public function reject(OtRequest $request, int $reviewerId, string $comment): void
    {
        $request->update([
            'status' => 'rejected',
            'reviewer_id' => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at' => now(),
        ]);
    }

    /**
     * Calculate OT hours: either from linked attendance or from the requested window.
     */
    public function calculateOtHours(OtRequest $request): float
    {
        if ($request->attendance_id && $request->attendance) {
            $totalWorked = (float) $request->attendance->total_hours;
            $ot = max(0, $totalWorked - self::STANDARD_HOURS);

            return round($ot, 2);
        }

        return round($request->requested_hours, 2);
    }

    /**
     * Get total unpaid OT amount for an employee (for payroll inclusion).
     */
    public function getPendingOtAmount(Employee $employee): float
    {
        return (float) $employee->overtimeRecords()
            ->where('is_paid', false)
            ->sum('ot_amount');
    }

    /**
     * Get all unpaid OT records for an employee (used when generating payslip).
     *
     * @return Collection<int, OvertimeRecord>
     */
    public function getUnpaidRecords(Employee $employee): Collection
    {
        return $employee->overtimeRecords()->unpaid()->orderBy('work_date')->get();
    }

    /**
     * Mark OT records as paid after payslip generation.
     *
     * @param  Collection<int, OvertimeRecord>  $records
     */
    public function markAsPaid(Collection $records, int $payslipId): void
    {
        $ids = $records->pluck('id')->all();
        OvertimeRecord::whereIn('id', $ids)->update([
            'payslip_id' => $payslipId,
            'is_paid' => true,
        ]);
    }

    public function createOvertimeRecordFromApprovedRequest(OtRequest $request): OvertimeRecord
    {
        if ($request->status !== 'approved') {
            throw new \DomainException('Overtime records can only be created for approved OT requests.');
        }

        if ($request->overtimeRecord()->exists()) {
            return $request->overtimeRecord()->firstOrFail();
        }

        if (OvertimeRecord::where('ot_request_id', $request->id)->exists()) {
            throw new \DomainException('Duplicate overtime record detected for this OT request.');
        }

        if ($request->attendance_id) {
            $attendance = Attendance::find($request->attendance_id);
            if (! $attendance || $attendance->employee_id !== $request->employee_id || $attendance->date->toDateString() !== $request->work_date->toDateString()) {
                throw new \DomainException('OT request attendance does not match employee/date constraints.');
            }
        }

        $otHours = $this->calculateOtHours($request);

        return OvertimeRecord::create([
            'employee_id' => $request->employee_id,
            'ot_request_id' => $request->id,
            'attendance_id' => $request->attendance_id,
            'work_date' => $request->work_date,
            'total_hours_worked' => $request->attendance?->total_hours ?? $otHours + self::STANDARD_HOURS,
            'standard_hours' => self::STANDARD_HOURS,
            'ot_hours' => $otHours,
            'rate_per_hour' => self::RATE_PER_HOUR,
            'ot_amount' => round($otHours * self::RATE_PER_HOUR, 2),
            'is_paid' => false,
        ]);
    }
}
