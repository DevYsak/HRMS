<?php

namespace App\Notifications;

use App\Models\WfhRequest;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class WfhRequestNotification extends Notification implements ShouldQueue
{
    use NotifiesByRole;
    use Queueable, SendsMailChannel, SerializesModels;

    /**
     * The status when the event happened. The notification is queued: read
     * at send time, a request decided before the worker ran told approvers
     * it was "approved" and the employee twice (Phase 4).
     */
    public readonly string $statusAtDispatch;

    public function __construct(public readonly WfhRequest $wfhRequest)
    {
        $this->statusAtDispatch = (string) $wfhRequest->status;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->wfhRequest->employee->user->name;
        $dates = $this->wfhRequest->dateRangeLabel();
        $status = $this->statusAtDispatch;

        return match ($status) {
            'pending' => [
                'type' => 'wfh_request',
                'title' => 'New WFH Request',
                'body' => "{$employee} requested to work from home on {$dates}.",
                'action' => 'Review',
                'url' => '/wfh/manage',
                'icon' => 'home',
                'color' => 'blue',
            ],
            'approved' => [
                'type' => 'wfh_request',
                'title' => 'WFH Request Approved',
                'body' => "Your work-from-home request for {$dates} has been approved.",
                'action' => 'View',
                'url' => '/wfh/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            default => [
                'type' => 'wfh_request',
                'title' => 'WFH Request Rejected',
                'body' => "Your work-from-home request for {$dates} was rejected.",
                'action' => 'View',
                'url' => '/wfh/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
