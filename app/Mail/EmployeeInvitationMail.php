<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The invitation itself: a login address, a temporary password and a link that
 * stops working.
 *
 * Everything here is required rather than defaulted. A defaulted password is
 * how a published literal ends up in somebody's inbox, which this application
 * has done before.
 */
class EmployeeInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $temporaryPassword,
        public readonly string $acceptUrl,
        public readonly CarbonInterface $expiresAt,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.config('app.name').' HRMS',
        );
    }

    public function headers(): Headers
    {
        // Lets the mail gate and email log trace this back to its notification
        // setting, the same way every other admin-controlled mailable does.
        return new Headers(text: ['X-Notification-Key' => self::class]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.employee-invitation',
            with: [
                'loginUrl' => route('login'),
                'expiryHours' => (int) config('security.invitation_expiry_hours', 48),
            ],
        );
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        return [];
    }
}
