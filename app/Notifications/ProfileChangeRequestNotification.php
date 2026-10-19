<?php

namespace App\Notifications;

use App\Models\ProfileChangeRequest;
use App\Notifications\Concerns\LinksForRecipient;
use App\Notifications\Concerns\NotifiesByRole;
use App\Notifications\Concerns\SendsMailChannel;
use App\Services\Profile\ProfileFieldRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * A profile / KYC change request (Phase 4: these used to notify nobody).
 * Submitted → the approvers covering the employee; decided → the employee.
 * The status is captured when the event happens, not when the queue runs.
 */
class ProfileChangeRequestNotification extends Notification implements ShouldQueue
{
    use LinksForRecipient;
    use NotifiesByRole;
    use Queueable, SendsMailChannel, SerializesModels;

    public readonly string $statusAtDispatch;

    public function __construct(public readonly ProfileChangeRequest $changeRequest)
    {
        $this->statusAtDispatch = (string) $changeRequest->status;
        $this->afterCommit();
    }

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $field = ProfileFieldRegistry::label($this->changeRequest->field);
        $name = $this->changeRequest->employee?->user?->name ?? 'An employee';
        $review = $this->linkFor($notifiable, [['employees.profile', ['employee' => $this->changeRequest->employee_id]]]);
        $own = $this->linkFor($notifiable, [['profile.me']]);

        return match ($this->statusAtDispatch) {
            ProfileChangeRequest::STATUS_APPROVED => [
                'type' => 'profile_change', 'title' => 'Profile Change Approved',
                'body' => "Your change to {$field} was approved.", 'action' => 'View', 'url' => $own,
                'icon' => 'check-circle', 'color' => 'green',
            ],
            ProfileChangeRequest::STATUS_REJECTED => [
                'type' => 'profile_change', 'title' => 'Profile Change Not Approved',
                'body' => "Your change to {$field} was not approved".($this->changeRequest->reviewer_comment ? ': '.$this->changeRequest->reviewer_comment : '.'),
                'action' => 'View', 'url' => $own, 'icon' => 'x-circle', 'color' => 'red',
            ],
            default => [
                'type' => 'profile_change', 'title' => 'Profile Change Request',
                'body' => "{$name} asked to change {$field}.", 'action' => 'Review', 'url' => $review,
                'icon' => 'user-circle', 'color' => 'blue',
            ],
        };
    }
}
