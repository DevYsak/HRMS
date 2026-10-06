<?php

namespace App\Services\Notifications;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Sends a notification at most once per person per reason.
 *
 * The reason is a dedup key the caller builds — e.g.
 * "missing_checkout:{attendance_id}" or "coordinator:late:{employee}:{date}"
 * — and an interval bucket in it ("…:{hour block}") turns "once" into "at
 * most once per interval" for reminders. The ledger
 * (notification_dispatches) is claimed with insert-or-ignore before the
 * send, so two overlapping runs can never both send. Recipients go through
 * NotificationRecipientPolicy, so include / exclude settings apply and the
 * same person is never notified twice in one send.
 */
class NotificationDispatcher
{
    public function __construct(private NotificationRecipientPolicy $policy) {}

    /**
     * Notify one person once for this key. Returns whether it was sent now.
     */
    public function sendOnce(User $user, Notification $notification, string $dedupKey): bool
    {
        $key = substr($notification::class.'|'.$user->id.'|'.$dedupKey, 0, 191);

        $claimed = DB::table('notification_dispatches')->insertOrIgnore([
            'notification_key' => $notification::class,
            'recipient_user_id' => $user->id,
            'dedup_key' => $key,
            'sent_at' => now(),
        ]);

        if ($claimed === 0) {
            return false;
        }

        $user->notify($notification);

        return true;
    }

    /**
     * Resolve the configured recipients from the code's default list, then
     * notify each once for this key.
     *
     * @param  Collection<int, User>  $default
     * @param  callable(User): Notification  $make  builds the notification for one recipient
     * @return int how many were sent now
     */
    public function sendToRecipients(string $eventKey, Collection $default, callable $make, string $dedupKey, ?Employee $subject = null): int
    {
        $sent = 0;

        foreach ($this->policy->resolve($eventKey, $default, $subject) as $user) {
            $sent += $this->sendOnce($user, $make($user), $dedupKey) ? 1 : 0;
        }

        return $sent;
    }
}
