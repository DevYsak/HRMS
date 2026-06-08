<?php

namespace App\Notifications;

use App\Models\WfhRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class WfhRequestNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly WfhRequest $wfhRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->wfhRequest->employee->user->name;
        $dates = $this->wfhRequest->dateRangeLabel();
        $status = $this->wfhRequest->status;

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
