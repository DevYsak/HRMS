<?php

namespace App\Services\Notifications;

use App\Enums\UserRole;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Who receives a notification, stated once and by name.
 *
 * Recipient resolution used to be an ad-hoc `User::whereIn('role', [...])`
 * repeated at ~20 call sites, which made two different things look identical:
 * a deliberate shared queue that every HR admin is meant to see, and an
 * accidental blast to everyone holding a role. Each method here is one or the
 * other, and says which.
 *
 * The broadcasts below are deliberate. HR at this company is a small team
 * working a shared queue — a probation falling due or a document expiring is
 * not one named person's task, and routing it to an individual would mean it
 * is missed while they are on leave. They are broadcasts by design, not by
 * omission.
 */
class NotificationRecipients
{
    /**
     * The shared HR work queue: every HR admin plus super admins.
     *
     * DELIBERATE BROADCAST. Used for events that belong to HR as a function
     * rather than to any one person — escalations, expiries, reminders and
     * approvals with no assigned owner.
     *
     * @return Collection<int, User>
     */
    public function hrQueue(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::HrAdmin, UserRole::SuperAdmin])
            ->get();
    }

    /**
     * HR admins whose remit actually covers this employee.
     *
     * Narrower than {@see hrQueue()} — prefer it where the event is about one
     * employee and User::coversEmployee() can scope it.
     *
     * @return Collection<int, User>
     */
    public function hrCovering(Employee $employee): Collection
    {
        return $this->hrQueue()->filter(fn (User $u) => $u->coversEmployee($employee))->values();
    }

    /**
     * Finance approvers: the finance role plus super admins.
     *
     * DELIBERATE BROADCAST — a payment awaiting finance sign-off is a team
     * responsibility, not an individual's.
     *
     * @return Collection<int, User>
     */
    public function financeApprovers(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Finance, UserRole::SuperAdmin])
            ->get();
    }

    /**
     * Directors plus super admins.
     *
     * DELIBERATE BROADCAST — decisions escalated to director level are seen
     * by all of them.
     *
     * @return Collection<int, User>
     */
    public function directors(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::Director, UserRole::SuperAdmin])
            ->get();
    }

    /**
     * Everyone who can approve a payroll run when no approval policy is
     * configured.
     *
     * DELIBERATE BROADCAST, and a fallback: with a policy configured, payroll
     * notifies that policy's step approvers instead.
     *
     * @return Collection<int, User>
     */
    public function payrollApprovers(): Collection
    {
        return User::query()
            ->whereIn('role', [UserRole::SuperAdmin, UserRole::Finance, UserRole::Director])
            ->get();
    }

    /**
     * The employee's own user account, if they have one.
     */
    public function employee(Employee $employee): ?User
    {
        return $employee->user;
    }

    /**
     * The employee's reporting manager, if one is assigned.
     */
    public function manager(Employee $employee): ?User
    {
        return $employee->manager;
    }
}
