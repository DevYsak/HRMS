<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A commit that fixed (part of) a finding, with the tests that prove it. */
#[Fillable([
    'qa_finding_id', 'commit_hash', 'commit_message', 'files_changed', 'test_case_codes',
    'developer_note', 'environment', 'fixed_at', 'created_by',
])]
class QaFixLink extends Model
{
    protected function casts(): array
    {
        return [
            'files_changed' => 'array',
            'test_case_codes' => 'array',
            'fixed_at' => 'datetime',
        ];
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(QaFinding::class, 'qa_finding_id');
    }

    public function shortHash(): string
    {
        return substr($this->commit_hash, 0, 7);
    }
}
