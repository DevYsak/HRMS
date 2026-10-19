<?php

namespace App\Notifications;

use App\Notifications\Concerns\FormatsForRecipient;
use App\Models\HolidayWorkRequest;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Notifies approvers of a new holiday-work request, and the employee of its
 * outcome. Queued (fans out to manager + HR) — see the SMTP 421 fix.
 */
class HolidayWorkRequestNotification extends Notification implements ShouldQueue
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

    public function __construct(public HolidayWorkRequest $request)
    {
        $this->statusAtDispatch = (string) $request->status;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $date = Carbon::parse($this->request->work_date)->format($this->recipientDateFormat($notifiable));
        $name = $this->request->employee?->user?->name ?? 'An employee';

        return match ($this->statusAtDispatch) {
            'approved' => [
                'type' => 'holiday_work',
                'title' => 'Holiday Work Approved',
                'body' => "Your request to work on {$date} was approved.",
                'action' => 'View',
                'url' => '/attendance/my',
                'icon' => 'check-circle',
                'color' => 'green',
            ],
            'rejected' => [
                'type' => 'holiday_work',
                'title' => 'Holiday Work Rejected',
                'body' => "Your request to work on {$date} was not approved.",
                'action' => 'View',
                'url' => '/time-off/my',
                'icon' => 'x-circle',
                'color' => 'red',
            ],
            default => [
                'type' => 'holiday_work',
                'title' => 'Holiday Work Request',
                'body' => "{$name} requested to work on the {$date} holiday.",
                'action' => 'Review',
                'url' => '/attendance/command-center',
                'icon' => 'briefcase',
                'color' => 'amber',
            ],
        };
    }
}
