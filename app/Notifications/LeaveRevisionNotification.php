<?php

namespace App\Notifications;

use App\Models\LeaveRequestRevision;
use App\Notifications\Concerns\FormatsForRecipient;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * An HR-proposed change to a leave request: to the employee when HR proposes
 * it, to HR when the employee accepts or rejects it.
 */
class LeaveRevisionNotification extends Notification
{
    use FormatsForRecipient;
    use SendsMailChannel;

    public const PROPOSED = 'proposed';

    public const ACCEPTED = 'accepted';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly LeaveRequestRevision $revision,
        public readonly string $stage,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $leave = $this->revision->leaveRequest;
        $format = $this->recipientDateFormat($notifiable);
        $dates = $leave->start_date->format($format).($leave->end_date->ne($leave->start_date) ? ' – '.$leave->end_date->format($format) : '');
        $type = $leave->leaveType?->name ?? 'Leave';
        $employee = $this->revision->employee?->user?->name ?? 'The employee';

        return match ($this->stage) {
            self::PROPOSED => [
                'type' => 'leave_change_proposed',
                'title' => 'HR proposed a change to your leave',
                'body' => "HR proposed changes to your {$type} request ({$dates}): “{$this->revision->reason}”. Review and accept or reject them — nothing is approved until you do.",
                'action' => 'Review',
                'url' => route('time-off.my').'#request-'.$leave->id,
                'icon' => 'pencil-square',
                'color' => 'amber',
                'leave_request_id' => $leave->id,
            ],
            self::ACCEPTED => [
                'type' => 'leave_change_accepted',
                'title' => 'Proposed leave change accepted',
                'body' => "{$employee} accepted the changes to their {$type} request ({$dates}). It is back with HR for the final decision.",
                'action' => 'Review',
                'url' => route('time-off.leave-requests.show', $leave),
                'icon' => 'check-circle',
                'color' => 'emerald',
                'leave_request_id' => $leave->id,
            ],
            default => [
                'type' => 'leave_change_rejected',
                'title' => 'Proposed leave change rejected',
                'body' => "{$employee} kept their {$type} request ({$dates}) as submitted: “{$this->revision->response_note}”.",
                'action' => 'Review',
                'url' => route('time-off.leave-requests.show', $leave),
                'icon' => 'x-circle',
                'color' => 'rose',
                'leave_request_id' => $leave->id,
            ],
        };
    }
}
