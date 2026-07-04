<?php

namespace App\Notifications;

use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class AttendanceRegularisationNotification extends Notification implements ShouldQueue
{
    use Queueable, SendsMailChannel;

    public function __construct(
        public readonly string $employeeName,
        public readonly string $date,
        public readonly string $status, // 'pending' | 'approved' | 'rejected'
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return match ($this->status) {
            'pending' => [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation Request',
                'body' => "{$this->employeeName} requested attendance regularisation for {$this->date}.",
                'action' => 'Review',
                'url' => '/attendance/employees',
                'icon' => 'clipboard-document-check',
                'color' => 'amber',
            ],
            'approved' => [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation Approved',
                'body' => "Your attendance regularisation for {$this->date} has been approved.",
                'action' => 'View',
                'url' => '/attendance/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            default => [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation Rejected',
                'body' => "Your attendance regularisation for {$this->date} was rejected.",
                'action' => 'View',
                'url' => '/attendance/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
        };
    }
}
