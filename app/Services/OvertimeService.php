<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OvertimeRecord;
use Illuminate\Support\Facades\DB;

class OvertimeService
{
    public const STANDARD_HOURS   = 9.0;
    public const RATE_PER_HOUR    = 100.0;

    /**
     * Create a pre-approval OT request for an employee.
     *
     * @param  array{work_date: string, start_time: string, end_time: string, reason: string, attendance_id?: int} $data
     */
    public function submitRequest(Employee $employee, array $data): OtRequest
    {
        $start   = \Carbon\Carbon::parse("{$data['work_date']} {$data['start_time']}");
        $end     = \Carbon\Carbon::parse("{$data['work_date']} {$data['end_time']}");
        $hours   = max(0, $end->floatDiffInHours($start));

        return OtRequest::create([
            'employee_id'      => $employee->id,
            'attendance_id'    => $data['attendance_id'] ?? null,
            'work_date'        => $data['work_date'],
            'start_time'       => $data['start_time'],
            'end_time'         => $data['end_time'],
            'requested_hours'  => round($hours, 2),
            'reason'           => $data['reason'],
            'status'           => 'pending',
        ]);
    }

    /**
     * Approve an OT request and materialise the OvertimeRecord.
     */
    public function approve(OtRequest $request, int $reviewerId, ?string $comment = null): OvertimeRecord
    {
        return DB::transaction(function () use ($request, $reviewerId, $comment) {
            $request->update([
                'status'           => 'approved',
                'reviewer_id'      => $reviewerId,
                'reviewer_comment' => $comment,
                'reviewed_at'      => now(),
            ]);

            // Determine actual OT from attendance if linked, else use requested hours
            $otHours = $this->calculateOtHours($request);

            return OvertimeRecord::create([
                'employee_id'        => $request->employee_id,
                'ot_request_id'      => $request->id,
                'attendance_id'      => $request->attendance_id,
                'work_date'          => $request->work_date,
                'total_hours_worked' => $request->attendance?->total_hours ?? $otHours + self::STANDARD_HOURS,
                'standard_hours'     => self::STANDARD_HOURS,
                'ot_hours'           => $otHours,
                'rate_per_hour'      => self::RATE_PER_HOUR,
                'ot_amount'          => round($otHours * self::RATE_PER_HOUR, 2),
                'is_paid'            => false,
            ]);
        });
    }

    /**
     * Reject an OT request.
     */
    public function reject(OtRequest $request, int $reviewerId, string $comment): void
    {
        $request->update([
            'status'           => 'rejected',
            'reviewer_id'      => $reviewerId,
            'reviewer_comment' => $comment,
            'reviewed_at'      => now(),
        ]);
    }

    /**
     * Calculate OT hours: either from linked attendance or from the requested window.
     */
    public function calculateOtHours(OtRequest $request): float
    {
        if ($request->attendance_id && $request->attendance) {
            $totalWorked = (float) $request->attendance->total_hours;
            $ot          = max(0, $totalWorked - self::STANDARD_HOURS);
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
     * @return \Illuminate\Database\Eloquent\Collection<int, OvertimeRecord>
     */
    public function getUnpaidRecords(Employee $employee): \Illuminate\Database\Eloquent\Collection
    {
        return $employee->overtimeRecords()->unpaid()->orderBy('work_date')->get();
    }

    /**
     * Mark OT records as paid after payslip generation.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, OvertimeRecord> $records
     */
    public function markAsPaid(\Illuminate\Database\Eloquent\Collection $records, int $payslipId): void
    {
        $ids = $records->pluck('id')->all();
        OvertimeRecord::whereIn('id', $ids)->update([
            'payslip_id' => $payslipId,
            'is_paid'    => true,
        ]);
    }
}
