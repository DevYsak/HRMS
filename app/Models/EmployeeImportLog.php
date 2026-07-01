<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Summary record for a bulk employee-import run.
 */
class EmployeeImportLog extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'mode',
        'total_rows',
        'imported',
        'updated',
        'skipped',
        'failed',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'errors' => 'array',
            'total_rows' => 'integer',
            'imported' => 'integer',
            'updated' => 'integer',
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
