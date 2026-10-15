<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** A screenshot, PDF, log or note attached to a test case or finding. Files live on the private disk only. */
#[Fillable(['evidenceable_type', 'evidenceable_id', 'kind', 'path', 'original_name', 'note', 'uploaded_by'])]
class QaEvidence extends Model
{
    protected $table = 'qa_evidence';

    /** The private disk (storage/app/private) — never the public one. */
    public const DISK = 'local';

    /** @var array<string, string> */
    public const KINDS = [
        'screenshot' => 'Screenshot',
        'screenshot_before' => 'Screenshot — before',
        'screenshot_after' => 'Screenshot — after',
        'pdf' => 'PDF',
        'log' => 'Log',
        'note' => 'Note',
    ];

    public function evidenceable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
