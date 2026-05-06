<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\User;
use App\Notifications\LeaveRequestNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class LeaveService
{
    /**
     * FIX 2 — Spec §3.3: half-day flag reduces deduction to 0.5 days.
     */
    public function submitRequest(
        Employee $employee,
        LeaveType $leaveType,
        string $startDate,
        string $endDate,
        string $reason,
        bool $isHalfDay = false,
    ): LeaveRequest {
        $start = Carbon::parse($startDate);
        $end = Carbon::parse($endDate);

        $days = $isHalfDay ? 0.5 : ($start->diffInDays($end) + 1);

        if ($leaveType->is_paid) {
            $balance = $this->getBalance($employee->id, $leaveType->id);
            $available = $balance ? max(0, $balance->allocated_days - $balance->used_days - ($balance->encashed_days ?? 0)) : 0;

            if ($available < $days) {
                throw new \DomainException('Insufficient balance for this leave request.');
            }
        }

        $request = LeaveRequest::create([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'is_half_day' => $isHalfDay,
            'days' => $days,
            'reason' => $reason,
            'status' => 'pending',
        ]);

        // Notify manager first; fall back to all HR admins if no manager assigned
        $request->load(['employee.user', 'leaveType']);
        $manager = $employee->manager;
        if ($manager) {
            $manager->notify(new LeaveRequestNotification($request));
        } else {
            User::whereIn('role', ['hr_admin', 'super_admin'])->each(
                fn ($hr) => $hr->notify(new LeaveRequestNotification($request))
            );
        }

        return $request;
    }

    public function reviewRequest(LeaveRequest $leaveRequest, array $data, string $status, int $reviewerId, ?string $comment = null): LeaveRequest
    {
        return DB::transaction(function () use ($leaveRequest, $data, $status, $reviewerId, $comment) {
            $oldStatus = $leaveRequest->status;
            $oldDays = (float) $leaveRequest->days;
            $oldTypeId = $leaveRequest->leave_type_id;
            $employee = $leaveRequest->employee;

            $start = Carbon::parse($data['start_date']);
            $end = Carbon::parse($data['end_date']);
            $isHalfDay = (bool) ($data['is_half_day'] ?? false);
            $newDays = $isHalfDay ? 0.5 : ($start->diffInDays($end) + 1);

            if ($oldStatus === 'approved') {
                $oldBalance = $this->getBalance($employee->id, $oldTypeId);
                $oldBalance?->decrement('used_days', $oldDays);
            }

            $leaveRequest->update([
                'status' => $status,
                'leave_type_id' => $data['leave_type_id'],
                'start_date' => $data['start_date'],
                'end_date' => $data['end_date'],
                'is_half_day' => $isHalfDay,
                'days' => $newDays,
                'reason' => $data['reason'],
                'reviewer_id' => $reviewerId,
                'reviewer_comment' => $comment,
            ]);

            if ($status === 'approved') {
                $leaveType = LeaveType::find($data['leave_type_id']);

                if ($leaveType?->is_paid) {
                    $newBalance = $this->getBalance($employee->id, (int) $data['leave_type_id']);

                    // Auto-create balance with 0 days if HR is approving and record doesn't exist yet
                    if (! $newBalance) {
                        $newBalance = LeaveBalance::create([
                            'employee_id' => $employee->id,
                            'leave_type_id' => (int) $data['leave_type_id'],
                            'year' => now()->year,
                            'allocated_days' => 0,
                            'used_days' => 0,
                            'carried_forward_days' => 0,
                            'encashed_days' => 0,
                            'comp_off_credits' => 0,
                        ]);
                    }

                    $available = max(0, $newBalance->allocated_days - $newBalance->used_days - ($newBalance->encashed_days ?? 0));
                    if ($available < $newDays) {
                        throw new \DomainException("Insufficient leave balance. Available: {$available} day(s), requested: {$newDays}.");
                    }

                    $newBalance->increment('used_days', $newDays);
                }
            }

            $fresh = $leaveRequest->fresh(['employee.user', 'leaveType', 'reviewer']);

            // Notify employee about the approval/rejection decision
            $fresh->employee->user->notify(new LeaveRequestNotification($fresh));

            return $fresh;
        });
    }

    public function saveLeaveType(array $data, ?int $id = null): LeaveType
    {
        return LeaveType::updateOrCreate(
            ['id' => $id],
            [
                'name' => $data['name'],
                'is_paid' => $data['is_paid'],
                'color' => $data['color'],
                'category' => $data['category'],
                'allow_carry_forward' => $data['allow_carry_forward'],
                'carry_forward_limit' => $data['carry_forward_limit'],
                'allow_encashment' => $data['allow_encashment'],
            ],
        );
    }

    public function deleteLeaveType(LeaveType $leaveType): void
    {
        $leaveType->delete();
    }

    public function carryForwardBalances(int $targetYear): void
    {
        $sourceYear = $targetYear - 1;
        $activeEmployees = Employee::where('status', 'active')->get();
        $leaveTypes = LeaveType::all();

        DB::transaction(function () use ($activeEmployees, $leaveTypes, $targetYear, $sourceYear) {
            foreach ($activeEmployees as $employee) {
                foreach ($leaveTypes as $type) {
                    $balance = LeaveBalance::where('employee_id', $employee->id)
                        ->where('leave_type_id', $type->id)
                        ->where('year', $sourceYear)
                        ->first();

                    if (! $balance) {
                        continue;
                    }

                    $carryForward = max(0, $balance->allocated_days - $balance->used_days);

                    LeaveBalance::updateOrCreate([
                        'employee_id' => $employee->id,
                        'leave_type_id' => $type->id,
                        'year' => $targetYear,
                    ], [
                        'allocated_days' => $carryForward,
                        'used_days' => 0,
                        'carried_forward_days' => $carryForward,
                        'encashed_days' => 0,
                        'comp_off_credits' => 0,
                    ]);
                }
            }
        });
    }

    public function creditCompOff(Employee $employee, Carbon $date, float $days = 1.0): LeaveBalance
    {
        $leaveType = LeaveType::firstOrCreate(
            ['category' => 'comp_off'],
            [
                'name' => 'Comp Off',
                'is_paid' => true,
                'color' => '#06B6D4',
                'allow_carry_forward' => true,
                'carry_forward_limit' => 0,
                'allow_encashment' => false,
            ],
        );

        $balance = LeaveBalance::firstOrCreate([
            'employee_id' => $employee->id,
            'leave_type_id' => $leaveType->id,
            'year' => $date->year,
        ], [
            'allocated_days' => 0,
            'used_days' => 0,
            'carried_forward_days' => 0,
            'encashed_days' => 0,
            'comp_off_credits' => 0,
        ]);

        $balance->incrementEach([
            'allocated_days' => $days,
            'comp_off_credits' => $days,
        ]);

        return $balance->fresh();
    }

    public function getBalance(int $employeeId, int $leaveTypeId, ?int $year = null): ?LeaveBalance
    {
        $year ??= now()->year;

        return LeaveBalance::where('employee_id', $employeeId)
            ->where('leave_type_id', $leaveTypeId)
            ->where('year', $year)
            ->first();
    }
}
