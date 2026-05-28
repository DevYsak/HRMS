<?php

namespace App\Notifications;

use App\Models\Document;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class DocumentExpiryNotification extends Notification
{
    use SendsMailChannel;

    /** @param  Collection<int, Document>  $documents */
    public function __construct(public readonly Collection $documents) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $count = $this->documents->count();

        return [
            'type' => 'document_expiry',
            'title' => 'Documents Expiring Soon',
            'body' => "{$count} document(s) are expiring within 30 days. Please review and renew as needed.",
            'action' => 'View Documents',
            'url' => '/documents',
            'icon' => 'document-text',
            'color' => 'amber',
        ];
    }
}
