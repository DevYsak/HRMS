<?php

namespace App\Mail;

use App\Models\IncrementProposal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

/** Increment letter delivery — one of the few email-permitted actions. */
class IncrementLetterMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public IncrementProposal $proposal) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your Increment Letter — FY {$this->proposal->cycle->financial_year}",
        );
    }

    public function headers(): Headers
    {
        return new Headers(text: ['X-Notification-Key' => self::class]);
    }

    public function content(): Content
    {
        $name = $this->proposal->employee->user->name ?? 'Team member';
        $effective = $this->proposal->cycle->effective_date->format('d M Y');

        return new Content(
            htmlString: "<p>Dear {$name},</p><p>Congratulations! Please find attached your increment letter for FY {$this->proposal->cycle->financial_year}, effective {$effective}.</p><p>Best regards,<br>HR Team, Conexus</p>",
        );
    }

    public function attachments(): array
    {
        $path = $this->proposal->letter_path;

        return $path && Storage::disk('local')->exists($path)
            ? [Attachment::fromStorageDisk('local', $path)->as("increment-letter-{$this->proposal->cycle->financial_year}.pdf")->withMime('application/pdf')]
            : [];
    }
}
