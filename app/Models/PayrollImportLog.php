<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Summary record for a bulk historical-payslip-import run.
 */
class PayrollImportLog extends Model
{
    protected $fillable = [
        'user_id',
        'filename',
        'total_rows',
        'imported',
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
            'skipped' => 'integer',
            'failed' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
