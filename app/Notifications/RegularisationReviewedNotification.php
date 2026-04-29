<?php

namespace App\Notifications;

use App\Models\AttendanceRegularisation;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RegularisationReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AttendanceRegularisation $regularisation) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $status = $this->regularisation->status;
        $date = Carbon::parse($this->regularisation->work_date)->format('d M Y');

        return match ($status) {
            'approved' => [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation Approved',
                'body' => "Your attendance correction for {$date} has been approved.",
                'action' => 'View',
                'url' => '/attendance/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            default => [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation Rejected',
                'body' => "Your attendance correction request for {$date} was not approved.",
                'action' => 'View',
                'url' => '/attendance/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
