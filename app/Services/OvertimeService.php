<?php

namespace App\Services;

use App\Models\Attendance;
use App\Models\AttendanceSetting;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\OtRequest;
use App\Models\OtWindow;
use App\Models\OvertimeRecord;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class OvertimeService
{
    /** @deprecated Use $employee->shift?->ot_threshold_hours ?? 9.0 instead */
    public const STANDARD_HOURS = 9.0;

    /** Fallback OT rate when no attendance setting is configured. */
    public const RATE_PER_HOUR = 100.0;

    /**
     * The configured OT pay rate (₹/hr), read from attendance settings so HR
     * can change it without a code deploy. Falls back to the default constant.
     */
    public function otRatePerHour(): float
    {
        return (float) (AttendanceSetting::query()->value('ot_rate_per_hour') ?? self::RATE_PER_HOUR);
    }

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
     * Auto-file a pending OT request from an attendance row whose net hours
     * exceed the employee's OT threshold. Used when an approved regularisation
     * corrects a day into overtime. Mirrors the Nexflow auto-sync: bypasses the
     * manual OT window (this is a system-triggered path), duplicate-guarded, and
     * left pending so HR still approves the actual overtime.
     */
    public function autoCreateFromAttendance(Attendance $attendance, string $context = 'approved regularisation'): ?OtRequest
    {
        $employee = $attendance->employee;

        if (! $employee || ! $attendance->date) {
            return null;
        }

        $threshold = $this->getThresholdForEmployee($employee);
        $netHours = (float) $attendance->total_hours;
        $otHours = round(max(0, $netHours - $threshold), 2);

        if ($otHours <= 0) {
            return null;
        }

        $workDate = $attendance->date->toDateString();

        $alreadyLogged = OtRequest::where('employee_id', $employee->id)
            ->where('work_date', $workDate)
            ->whereIn('status', ['pending', 'approved'])
            ->exists();

        if ($alreadyLogged) {
            return null;
        }

        // Derive a synthetic OT window from shift start + threshold, same as Nexflow.
        $shiftStartHour = (int) ($employee->shift?->start_time ? date('H', strtotime($employee->shift->start_time)) : 9);
        $otStartTime = sprintf('%02d:00', ($shiftStartHour + (int) $threshold) % 24);
        $otEndTime = date('H:i', strtotime($otStartTime) + ((int) round($otHours * 60) * 60));

        return OtRequest::create([
            'employee_id' => $employee->id,
            'attendance_id' => $attendance->id,
            'work_date' => $workDate,
            'start_time' => $otStartTime,
            'end_time' => $otEndTime,
            'requested_hours' => $otHours,
            'reason' => "Auto-detected from {$context}: net {$netHours}h worked, {$otHours}h OT.",
            'status' => 'pending',
            'source' => 'regularisation',
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

    /**
     * Whether an employee is eligible for Nexflow-sourced OT tracking.
     */
    public function isNexflowEligible(Employee $employee): bool
    {
        return in_array($employee->ot_tracking_source, ['nexflow', 'hybrid'], true);
    }

    /**
     * Auto-approve a pending OT request and create the overtime record.
     * Used by the Nexflow sync for auto-approve eligible employees.
     */
    public function autoApprove(OtRequest $request): OvertimeRecord
    {
        return DB::transaction(function () use ($request) {
            if ($request->status !== 'pending') {
                throw new \DomainException('Only pending OT requests can be auto-approved.');
            }

            $request->update([
                'status' => 'approved',
                'reviewer_id' => null,
                'reviewer_comment' => 'Auto-approved via Nexflow sync',
                'reviewed_at' => now(),
            ]);

            return $this->createOvertimeRecordFromApprovedRequest($request->fresh(['attendance']));
        });
    }

    /**
     * Get the effective standard hours threshold for an employee.
     */
    public function getThresholdForEmployee(Employee $employee): float
    {
        return (float) ($employee->shift?->ot_threshold_hours ?? self::STANDARD_HOURS);
    }

    /**
     * Import one Nexflow NexBridge ot-details record into HRMS as an approved
     * OT request + materialised OvertimeRecord, so it flows into payroll.
     *
     * Only fully signed-off records (headline status 'approved' — both Nexflow
     * levels cleared) are payable. Idempotent and double-count safe: skips when
     * an OT already exists for the employee on that date, matching the automated
     * hrms:sync-nexflow-ot dedup. The OtRequest carries source='nexflow' and a
     * nexflow_ref for the audit trail.
     *
     * Approved records are imported as approved OT + a materialised OvertimeRecord
     * (payable). Rejected records are recorded as a rejected OT request so admin/HR
     * can see them (not payable). Pending records are left alone until decided.
     * Idempotent (deduped by nexflow_ref) and double-count safe.
     *
     * @param  array{id?: int|string, date?: string, status?: string, reason?: string, ot_hours?: float, ot_minutes?: int, sessions?: array<int, array{started_at?: string, stopped_at?: string}>}  $record
     * @return array{status: string, record: ?OvertimeRecord} status: imported | recorded_rejected | skipped | not_payable
     */
    public function importNexflowOtRecord(Employee $employee, array $record): array
    {
        // Map Nexflow's headline status to the HRMS OT status.
        $hrmsStatus = match ($record['status'] ?? null) {
            'approved' => 'approved',
            'rejected' => 'rejected',
            'pending' => 'pending',
            default => null,
        };

        $workDate = $record['date'] ?? null;
        $otHours = round((float) ($record['ot_hours'] ?? (($record['ot_minutes'] ?? 0) / 60)), 2);

        if ($hrmsStatus === null || ! $workDate || $otHours <= 0) {
            return ['status' => 'not_payable', 'record' => null];
        }

        $ref = 'otdetails:'.($record['id'] ?? $workDate);
        $existing = OtRequest::where('source', 'nexflow')->where('nexflow_ref', $ref)->first();

        // Already synced — reconcile if Nexflow changed the status since. Its
        // approvals can be re-decided (approved → rejected → approved), and each
        // change must flow through to HRMS with a history entry.
        if ($existing) {
            if ($existing->status === $hrmsStatus) {
                return ['status' => 'unchanged', 'record' => $existing->overtimeRecord];
            }

            return $this->applyNexflowStatusChange($existing, $hrmsStatus);
        }

        // A brand-new pending record: nothing to record until it's decided.
        if ($hrmsStatus === 'pending') {
            return ['status' => 'not_payable', 'record' => null];
        }

        // Derive the OT window from the completed sessions (first start → last stop).
        $sessions = collect($record['sessions'] ?? []);
        $startTime = $sessions->pluck('started_at')->filter()->sort()->first() ?: '18:00';
        $endTime = $sessions->pluck('stopped_at')->filter()->sort()->last()
            ?: date('H:i', strtotime($startTime) + (int) round($otHours * 3600));

        // Approved — double-count guard: a pending/approved OT already covers this day.
        if ($hrmsStatus === 'approved'
            && OtRequest::where('employee_id', $employee->id)->where('work_date', $workDate)
                ->whereIn('status', ['pending', 'approved'])->exists()) {
            return ['status' => 'skipped', 'record' => null];
        }

        $otRequest = OtRequest::create([
            'employee_id' => $employee->id,
            'work_date' => $workDate,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'requested_hours' => $otHours,
            'reason' => 'Nexflow OT: '.($record['reason'] ?? 'overtime')
                .($hrmsStatus === 'approved' ? ' (L1 + L2 approved)' : ' (rejected in Nexflow)'),
            'status' => $hrmsStatus,
            'reviewer_comment' => $hrmsStatus === 'approved'
                ? 'Imported from Nexflow — both approval levels cleared.'
                : 'Rejected in Nexflow (L1/L2).',
            'reviewed_at' => now(),
            'source' => 'nexflow',
            'nexflow_ref' => $ref,
        ]);

        // First history entry for this OT.
        AuditLog::record($otRequest, 'nexflow_ot_synced', null, ['status' => $hrmsStatus, 'ref' => $ref]);

        if ($hrmsStatus === 'approved') {
            return ['status' => 'imported', 'record' => $this->createOvertimeRecordFromApprovedRequest($otRequest)];
        }

        return ['status' => 'recorded_rejected', 'record' => null];
    }

    /**
     * Reconcile an existing Nexflow-sourced OT request when its status changed
     * upstream. Records a history entry (audit log) for the change and keeps
     * payroll honest: re-approval materialises the overtime record; a later
     * rejection/pending voids the *unpaid* overtime record so it isn't paid.
     * An OT already paid into a payslip is never silently removed — it's flagged.
     *
     * @return array{status: string, record: ?OvertimeRecord}
     */
    protected function applyNexflowStatusChange(OtRequest $existing, string $newStatus): array
    {
        $oldStatus = $existing->status;
        $alreadyPaid = $existing->overtimeRecord()->where('is_paid', true)->exists();

        return DB::transaction(function () use ($existing, $newStatus, $oldStatus, $alreadyPaid) {
            $existing->update([
                'status' => $newStatus,
                'reviewer_comment' => "Nexflow status changed: {$oldStatus} → {$newStatus}.",
                'reviewed_at' => now(),
            ]);

            AuditLog::record($existing, 'nexflow_ot_status_changed',
                ['status' => $oldStatus],
                ['status' => $newStatus, 'already_paid' => $alreadyPaid],
            );

            if ($newStatus === 'approved') {
                $rec = $existing->overtimeRecord ?? $this->createOvertimeRecordFromApprovedRequest($existing->fresh());

                return ['status' => 'updated', 'record' => $rec];
            }

            // Rejected/pending — void the UNPAID overtime record so it doesn't pay.
            // Paid records stay (can't un-pay a closed payslip) but the change is audited.
            if (! $alreadyPaid) {
                $existing->overtimeRecord()->where('is_paid', false)->delete();
            }

            return ['status' => 'updated', 'record' => null];
        });
    }

    /**
     * Sync overtime for all active employees (optionally only Nexflow-eligible)
     * from the Nexflow ot-details endpoint for a period: import approved OT into
     * payroll and record rejected OT for visibility. Idempotent — safe to run
     * repeatedly (the manual button and the 10-minute scheduler both call this).
     *
     * @return array{imported: int, rejected: int, updated: int, skipped: int, employees: int}
     */
    public function syncNexflowOtDetails(?string $from = null, ?string $to = null, bool $eligibleOnly = false): array
    {
        $nexflow = app(NexflowApiService::class);

        $employees = Employee::query()
            ->where('status', 'active')
            ->whereHas('user', fn ($q) => $q->whereNotNull('email'))
            ->when($eligibleOnly, fn ($q) => $q->whereIn('ot_tracking_source', ['nexflow', 'hybrid']))
            ->with('user')
            ->get();

        $imported = 0;
        $rejected = 0;
        $updated = 0;
        $skipped = 0;

        foreach ($employees as $employee) {
            // Fetch fresh — the sync's job is to catch upstream status changes live.
            $data = $nexflow->getOtDetails($employee->user->email, $from, $to, null, fresh: true);

            foreach ($data['ot_records'] ?? [] as $record) {
                $outcome = $this->importNexflowOtRecord($employee, $record);
                match ($outcome['status']) {
                    'imported' => $imported++,
                    'recorded_rejected' => $rejected++,
                    'updated' => $updated++,     // status changed upstream and reconciled
                    'skipped' => $skipped++,
                    default => null,             // unchanged / not_payable
                };
            }
        }

        return compact('imported', 'rejected', 'updated', 'skipped') + ['employees' => $employees->count()];
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
            'rate_per_hour' => $rate = $this->otRatePerHour(),
            'ot_amount' => round($otHours * $rate, 2),
            'is_paid' => false,
        ]);
    }
}
