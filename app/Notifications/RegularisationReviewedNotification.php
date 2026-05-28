<?php

namespace App\Notifications;

use App\Models\AttendanceRegularisation;
use App\Notifications\Concerns\SendsMailChannel;
use Carbon\Carbon;
use Illuminate\Notifications\Notification;

class RegularisationReviewedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public AttendanceRegularisation $regularisation) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $status = $this->regularisation->status;
        $date = Carbon::parse($this->regularisation->work_date)->format('d M Y');

        return match ($status) {
            'pending' => [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation Request Submitted',
                'body' => "Your attendance correction request for {$date} has been submitted and is awaiting review.",
                'action' => 'View',
                'url' => '/attendance/my',
                'icon' => 'clipboard-document-check',
                'color' => 'amber',
            ],
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
