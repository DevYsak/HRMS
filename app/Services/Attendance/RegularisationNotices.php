<?php

namespace App\Services\Attendance;

use App\Models\AttendanceRegularisation;
use App\Models\User;
use App\Notifications\RegularisationReviewedNotification;
use App\Services\Notifications\NotificationCatalog;
use App\Services\Notifications\NotificationRecipientPolicy;

/**
 * Tells people a missed-punch (regularisation) request was decided: the
 * employee, plus whoever Admin's rules on Settings → Notifications & Email
 * add for that stage (approved / rejected) and the employee's department —
 * minus anyone excluded. The one place every approve / reject screen sends
 * it from.
 */
class RegularisationNotices
{
    public function __construct(private NotificationRecipientPolicy $policy) {}

    public function decided(AttendanceRegularisation $regularisation): void
    {
        $regularisation->loadMissing('employee.user');
        $employeeUser = $regularisation->employee?->user;
        $notification = new RegularisationReviewedNotification($regularisation);

        $this->policy
            ->resolve(NotificationCatalog::eventKeyFor($notification), collect([$employeeUser])->filter(), $regularisation->employee)
            ->each(fn (User $u) => $u->notify($notification->forRole($u->id === $employeeUser?->id ? 'employee' : 'recipient')));
    }
}
