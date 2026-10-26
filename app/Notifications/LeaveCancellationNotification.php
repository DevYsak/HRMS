<?php

namespace App\Notifications;

use App\Models\LeaveCancellationRequest;
use App\Notifications\Concerns\FormatsForRecipient;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * A request to cancel approved leave: to HR when the employee sends it, to
 * the employee when HR rejects it (an approval reaches them as the leave's
 * own cancellation notice).
 */
class LeaveCancellationNotification extends Notification
{
    use FormatsForRecipient;
    use SendsMailChannel;

    public const REQUESTED = 'requested';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly LeaveCancellationRequest $cancellation,
        public readonly string $stage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $leave = $this->cancellation->leaveRequest;
        $format = $this->recipientDateFormat($notifiable);
        $dates = $leave->start_date->format($format).($leave->end_date->ne($leave->start_date) ? ' – '.$leave->end_date->format($format) : '');
        $type = $leave->leaveType?->name ?? 'Leave';
        $employee = $this->cancellation->employee?->user?->name ?? 'An employee';

        return $this->stage === self::REQUESTED
            ? [
                'type' => 'leave_cancellation_requested',
                'title' => 'Leave cancellation request',
                'body' => "{$employee} asks to cancel approved {$type} ({$dates}, {$leave->days} day(s)): “{$this->cancellation->reason}”. The leave stays approved until you decide.",
                'action' => 'Review',
                'url' => route('time-off.leave-requests.show', $leave),
                'icon' => 'calendar-days',
                'color' => 'amber',
                'leave_request_id' => $leave->id,
            ]
            : [
                'type' => 'leave_cancellation_rejected',
                'title' => 'Cancellation request not approved',
                'body' => "HR kept your approved {$type} ({$dates}). Reason: “{$this->cancellation->decision_note}”.",
                'action' => 'View My Time Off',
                'url' => route('time-off.my'),
                'icon' => 'calendar-days',
                'color' => 'rose',
                'leave_request_id' => $leave->id,
            ];
    }
}
