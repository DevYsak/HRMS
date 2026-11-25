<?php

namespace App\Notifications;

use App\Models\AttendanceRegularisation;
use App\Notifications\Concerns\FormatsForRecipient;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * A missed-punch (regularisation) request was decided. To the employee, and
 * to anyone Admin's rules add (told as 'recipient' — about the employee,
 * not addressed as them). Sent through RegularisationNotices.
 */
class RegularisationReviewedNotification extends Notification implements ShouldQueue
{
    use FormatsForRecipient;
    use NotifiesByRole;
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

        // Someone Admin's rules keep informed: told about the employee.
        if ($this->role === 'recipient') {
            $name = $this->regularisation->employee?->user?->name ?? 'An employee';
            $outcome = match ($status) {
                'approved' => 'was approved',
                'rejected' => 'was not approved',
                default => 'is awaiting review',
            };

            return [
                'type' => 'attendance_regularisation',
                'title' => 'Regularisation '.($status === 'approved' ? 'Approved' : ($status === 'rejected' ? 'Rejected' : 'Submitted')).' — '.$name,
                'body' => "{$name}'s attendance correction for {$date} {$outcome}.",
                'action' => 'View',
                'url' => '/attendance/missed-punches',
                'icon' => $status === 'approved' ? 'check-circle' : ($status === 'rejected' ? 'x-circle' : 'clipboard-document-check'),
                'color' => $status === 'approved' ? 'green' : ($status === 'rejected' ? 'red' : 'amber'),
            ];
        }

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

    /** Approved or rejected — each configured on its own (NotificationCatalog::STAGES). */
    public function notificationStage(): ?string
    {
        return in_array($this->statusAtDispatch, ['approved', 'rejected'], true) ? $this->statusAtDispatch : null;
    }

    /**
     * What this notification is about, for the email history.
     *
     * @return array{type: string, id: int}
     */
    public function mailContext(): array
    {
        return ['type' => 'attendance_regularisation', 'id' => (int) $this->regularisation->id];
    }
}
