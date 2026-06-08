<?php

namespace App\Notifications;

use App\Models\Document;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class DocumentUploadedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly Document $document) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'document_uploaded',
            'title' => 'New Document Shared With You',
            'body' => "\"{$this->document->title}\" has been uploaded and shared with you.",
            'action' => 'View Document',
            'url' => '/documents',
            'icon' => 'document-text',
            'color' => 'blue',
        ];
    }
}
