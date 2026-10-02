<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

/**
 * Tells an employee that HR submitted a leave request for them. Carries the
 * request facts only — never HR's internal note.
 */
class LeaveAppliedOnBehalfNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(
        public readonly LeaveRequest $request,
        public readonly string $appliedBy,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $dates = $this->request->start_date->format('d M Y')
            .($this->request->end_date->ne($this->request->start_date) ? ' – '.$this->request->end_date->format('d M Y') : '');

        return [
            'type' => 'leave_applied_on_behalf',
            'title' => 'Leave applied on your behalf',
            'body' => "{$this->appliedBy} applied {$this->request->leaveType?->name} for you: {$dates} ({$this->request->days} day(s)). It now follows the normal approval process.",
            'action' => 'View My Time Off',
            'url' => route('time-off.my'),
            'icon' => 'calendar-days',
            'color' => 'blue',
            'leave_request_id' => $this->request->id,
        ];
    }
}
