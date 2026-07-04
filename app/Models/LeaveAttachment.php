<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single categorised attachment on a leave request (medical certificate,
 * manager letter, doctor certificate, travel ticket, supporting document, or
 * an optional voice note). A leave request can have any number of these.
 */
#[Fillable(['leave_request_id', 'type', 'path', 'original_name', 'mime_type', 'size'])]
class LeaveAttachment extends Model
{
    public function leaveRequest(): BelongsTo
    {
        return $this->belongsTo(LeaveRequest::class);
    }

    public static function typeLabels(): array
    {
        return [
            'medical_certificate' => 'Medical Certificate',
            'manager_letter' => 'Manager Letter',
            'doctor_certificate' => 'Doctor Certificate',
            'travel_ticket' => 'Travel Ticket',
            'supporting_document' => 'Supporting Document',
            'voice_note' => 'Voice Note',
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? ucfirst(str_replace('_', ' ', $this->type));
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    public function isAudio(): bool
    {
        return str_starts_with((string) $this->mime_type, 'audio/');
    }

    public function icon(): string
    {
        return match (true) {
            $this->isImage() => 'photo',
            $this->isPdf() => 'document-text',
            $this->isAudio() => 'microphone',
            str_ends_with((string) $this->mime_type, 'zip') => 'archive-box',
            default => 'paper-clip',
        };
    }

    public function humanSize(): string
    {
        $size = (int) $this->size;

        return match (true) {
            $size >= 1048576 => round($size / 1048576, 1).' MB',
            $size >= 1024 => round($size / 1024).' KB',
            default => $size.' B',
        };
    }
}
