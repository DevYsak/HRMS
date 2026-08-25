<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class WelcomeEmployeeMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * The password is required rather than defaulted. It previously fell back
     * to a published literal, so any caller that forgot the argument would mail
     * out a credential the whole company could guess.
     */
    public function __construct(
        public readonly User $user,
        public readonly string $temporaryPassword,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.config('app.name').' — Your Login Credentials',
        );
    }

    public function headers(): Headers
    {
        // Lets the mail gate/logger trace this message back to its notification setting.
        return new Headers(text: ['X-Notification-Key' => self::class]);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.welcome-employee',
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
