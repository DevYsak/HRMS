<?php

namespace App\Notifications;

use App\Models\OtRequest;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class OtRequestNotification extends Notification implements ShouldQueue
{
    use NotifiesByRole;
    use Queueable, SendsMailChannel, SerializesModels;

    /**
     * The status when the event happened. The notification is queued: read
     * at send time, a request decided before the worker ran told approvers
     * it was "approved" and the employee twice (Phase 4).
     */
    public readonly string $statusAtDispatch;

    public function __construct(public readonly OtRequest $otRequest)
    {
        $this->statusAtDispatch = (string) $otRequest->status;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->otRequest->employee->user->name;
        $date = $this->otRequest->work_date->format('M d, Y');
        $status = $this->statusAtDispatch;

        return match ($status) {
            'pending' => [
                'type' => 'ot_request',
                'title' => 'New OT Request',
                'body' => "{$employee} submitted an OT request for {$date}.",
                'action' => 'Review',
                'url' => '/overtime/manage',
                'icon' => 'clock',
                'color' => 'blue',
            ],
            'approved' => [
                'type' => 'ot_request',
                'title' => 'OT Request Approved',
                'body' => "Your OT request for {$date} has been approved.",
                'action' => 'View',
                'url' => '/overtime/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            default => [
                'type' => 'ot_request',
                'title' => 'OT Request Rejected',
                'body' => "Your OT request for {$date} was rejected.",
                'action' => 'View',
                'url' => '/overtime/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
