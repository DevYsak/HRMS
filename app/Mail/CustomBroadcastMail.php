<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * An ad-hoc broadcast email composed by an admin/HR user in Settings → Mail
 * Center. Stamps X-Notification-Key so it flows through the same mail gate and
 * email log as every other outgoing message.
 */
class CustomBroadcastMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $body,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    public function headers(): Headers
    {
        // Lets the mail gate/logger trace this message in the email log.
        return new Headers(text: ['X-Notification-Key' => 'custom.broadcast']);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.broadcast',
            with: ['body' => $this->body],
        );
    }
}
