<?php

namespace App\Notifications;

use App\Models\AttendanceRegularisation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class RegularisationReviewedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public AttendanceRegularisation $regularisation)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $status = ucfirst($this->regularisation->status);
        $date = \Carbon\Carbon::parse($this->regularisation->work_date)->format('M d, Y');
        return [
            'title' => "Attendance Regularisation {$status}",
            'message' => "Your request for {$date} has been {$this->regularisation->status}.",
            'url' => route('attendance.my-attendance'),
            'icon' => $this->regularisation->status === 'approved' ? 'check-circle' : 'x-circle',
            'icon_color' => $this->regularisation->status === 'approved' ? 'text-green-500' : 'text-red-500',
        ];
    }
}
