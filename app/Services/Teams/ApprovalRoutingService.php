<?php

namespace App\Services\Teams;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Resolves who approves an employee's request through the team structure
 * (v4 Part 3.2), replacing the flat reporting-manager routing:
 *
 *   1. Team Lead (primary approver)
 *   2. Secondary Lead — when the primary is on approved leave on the request date
 *   3. Department Head — escalation, or when the requester IS the team lead
 *   4. HR Admin — final escalation
 *
 * Returns the User who should act; getApproverChain() exposes the ordered
 * fallbacks for notification fan-out and escalation.
 */
class ApprovalRoutingService
{
    /**
     * The single approver for a request on a given date (first resolvable rung
     * of the chain), or null when nothing resolves (caller falls back to HR).
     */
    public function resolveApprover(Employee $employee, ?Carbon $onDate = null): ?User
    {
        return $this->getApproverChain($employee, $onDate)->first();
    }

    /**
     * The ordered approver chain for the employee on a date. Each entry is a
     * distinct User; unavailable rungs are skipped. Used for routing, for
     * notifying every relevant approver, and for 24-hour escalation.
     *
     * @return Collection<int, User>
     */
    public function getApproverChain(Employee $employee, ?Carbon $onDate = null): Collection
    {
        $onDate ??= Carbon::today();
        $chain = collect();

        $team = $employee->activeTeam();
        $isTeamLead = $team && $team->team_lead_id === $employee->id;

        if ($team && ! $isTeamLead) {
            $lead = $team->teamLead;
            // Primary lead — unless they're on approved leave that day.
            if ($lead && $lead->id !== $employee->id && ! $this->onApprovedLeave($lead, $onDate)) {
                $this->pushUser($chain, $lead->user);
            }

            // Secondary lead backs up an absent (or missing) primary.
            $secondary = $team->secondaryLead;
            if ($secondary && $secondary->id !== $employee->id && ! $this->onApprovedLeave($secondary, $onDate)) {
                $this->pushUser($chain, $secondary->user);
            }
        }

        // Department head — escalation, and the first approver when the
        // requester is themselves the team lead.
        $head = $employee->department?->head;
        if ($head && $head->id !== $employee->user_id) {
            $this->pushUser($chain, $head);
        }

        // Final escalation — all company-wide + in-scope HR admins.
        User::whereIn('role', [UserRole::HrAdmin, UserRole::SuperAdmin])->get()
            ->filter(fn (User $u) => $u->coversEmployee($employee))
            ->each(fn (User $u) => $this->pushUser($chain, $u));

        return $chain->values();
    }

    /** Is this employee on approved leave covering the given date? */
    protected function onApprovedLeave(Employee $employee, Carbon $date): bool
    {
        return LeaveRequest::where('employee_id', $employee->id)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $date->toDateString())
            ->whereDate('end_date', '>=', $date->toDateString())
            ->exists();
    }

    /** Append a user to the chain, de-duplicating by id. */
    protected function pushUser(Collection $chain, ?User $user): void
    {
        if ($user && ! $chain->contains(fn (User $u) => $u->id === $user->id)) {
            $chain->push($user);
        }
    }
}
