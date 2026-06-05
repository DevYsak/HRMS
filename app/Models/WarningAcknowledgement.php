<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'warning_letter_id', 'acknowledged_by',
    'acknowledgement_text', 'acknowledged_at', 'ip_address',
])]
class WarningAcknowledgement extends Model
{
    protected function casts(): array
    {
        return [
            'acknowledged_at' => 'datetime',
        ];
    }

    public function warningLetter(): BelongsTo
    {
        return $this->belongsTo(WarningLetter::class, 'warning_letter_id');
    }

    public function acknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
