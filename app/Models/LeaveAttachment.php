<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * A single categorised attachment on a leave request (medical certificate,
 * manager letter, doctor certificate, travel ticket, supporting document, or
 * an optional voice note). A leave request can have any number of these.
 */
#[Fillable(['leave_request_id', 'type', 'path', 'original_name', 'mime_type', 'size'])]
class LeaveAttachment extends Model
{
    /**
     * Private disk for new documents (medical certificates and the like):
     * never under public/storage, only through the leave-attachments route.
     * Older rows may still sit on the public disk; diskName() finds them.
     */
    public const DISK = 'local';

    /** The disk holding this file: the private one, else the legacy public one. */
    public function diskName(): ?string
    {
        foreach ([self::DISK, 'public'] as $disk) {
            if ($this->path && Storage::disk($disk)->exists($this->path)) {
                return $disk;
            }
        }

        return null;
    }

    /**
     * Store an uploaded document privately and return the attachment row's
     * fields. Name, type and size are read first: Livewire's temporary file
     * sits on the same private disk, so storing it moves it away.
     *
     * @return array{type: string, path: string, original_name: string, mime_type: ?string, size: int}
     */
    public static function storeUpload(UploadedFile $file, string $type = 'supporting_document'): array
    {
        $meta = [
            'type' => $type,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'size' => (int) $file->getSize(),
        ];

        return ['path' => $file->store('leave-attachments', self::DISK), ...$meta];
    }

    public function url(): string
    {
        return route('leave-attachments.show', $this);
    }

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
