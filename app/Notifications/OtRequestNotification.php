<?php

namespace App\Notifications;

use App\Models\OtRequest;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class OtRequestNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsMailChannel, SerializesModels;

    public function __construct(public readonly OtRequest $otRequest) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $employee = $this->otRequest->employee->user->name;
        $date = $this->otRequest->work_date->format('M d, Y');
        $status = $this->otRequest->status;

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
