<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsForRecipient;
use App\Models\AttendanceRegularisation;
use App\Notifications\Concerns\SendsMailChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

class RegularisationReviewedNotification extends Notification implements ShouldQueue
{
    use FormatsForRecipient;
    use Queueable, SendsMailChannel, SerializesModels;

    /**
     * The status when the event happened. The notification is queued: read
     * at send time, a request decided before the worker ran told approvers
     * it was "approved" and the employee twice (Phase 4).
     */
    public readonly string $statusAtDispatch;

    public function __construct(public AttendanceRegularisation $regularisation)
    {
        $this->statusAtDispatch = (string) $regularisation->status;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $status = $this->statusAtDispatch;
        $date = Carbon::parse($this->regularisation->work_date)->format($this->recipientDateFormat($notifiable));

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
