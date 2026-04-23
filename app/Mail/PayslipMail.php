<?php

namespace App\Mail;

use App\Models\Payslip;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PayslipMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public Payslip $payslip)
    {
    }

    public function envelope(): Envelope
    {
        $month = $this->payslip->payroll->month . ' ' . $this->payslip->payroll->year;
        $cycle = $this->payslip->payroll->cycle === 'cycle_a' ? 'Cycle A' : 'Cycle B';
        
        return new Envelope(
            subject: "Your Payslip for {$month} ({$cycle})",
        );
    }

    public function content(): Content
    {
        $month = $this->payslip->payroll->month . ' ' . $this->payslip->payroll->year;
        return new Content(
            htmlString: "<p>Dear {$this->payslip->employee->user->name},</p><p>Please find attached your payslip for the period of {$month} ({$this->payslip->payroll->cycle}).</p><p>Best regards,<br>Pulse HRMS Team</p>",
        );
    }

    public function attachments(): array
    {
        // Generate the PDF
        $pdf = Pdf::loadView('pdf.payslip', ['payslip' => $this->payslip]);
        $filename = 'payslip_' . $this->payslip->payroll->month . '_' . $this->payslip->payroll->year . '_' . $this->payslip->payroll->cycle . '.pdf';

        return [
            Attachment::fromData(fn () => $pdf->output(), $filename)
                ->withMime('application/pdf'),
        ];
    }
}
