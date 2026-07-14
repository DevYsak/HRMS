<?php

namespace App\Services\Teams;

use App\Models\DepartmentTeam;
use App\Models\DepartmentTeamMember;
use App\Models\Employee;
use Illuminate\Support\Facades\DB;

/**
 * Manage department teams and their membership (v4 Part 3). Enforces the
 * one-active-team-per-employee rule in the service layer, closing the prior
 * membership (left_at) rather than deleting it so team history is preserved.
 */
class TeamService
{
    /**
     * Move an employee into a team, ending any current active membership first.
     * Idempotent: re-adding to the same team is a no-op.
     */
    public function assign(Employee $employee, DepartmentTeam $team): DepartmentTeamMember
    {
        return DB::transaction(function () use ($employee, $team) {
            $current = $employee->teamMemberships()->where('is_active', true)->latest('id')->first();

            if ($current && (int) $current->department_team_id === $team->id) {
                return $current;
            }

            if ($current) {
                $current->update(['is_active' => false, 'left_at' => now()->toDateString()]);
            }

            return DepartmentTeamMember::create([
                'department_team_id' => $team->id,
                'employee_id' => $employee->id,
                'joined_at' => now()->toDateString(),
                'is_active' => true,
            ]);
        });
    }

    /** Remove an employee from their current team (keeps the historical row). */
    public function remove(Employee $employee): void
    {
        $employee->teamMemberships()
            ->where('is_active', true)
            ->update(['is_active' => false, 'left_at' => now()->toDateString()]);
    }

    /** Assign many employees to one team in a single pass. */
    public function assignMany(array $employeeIds, DepartmentTeam $team): void
    {
        Employee::whereIn('id', $employeeIds)->get()->each(fn (Employee $e) => $this->assign($e, $team));
    }
}
