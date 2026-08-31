<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Audit record for a single outgoing email, written by the Mail event listeners.
 */
class EmailLog extends Model
{
    protected $fillable = [
        'notification_key',
        'to_email',
        'to_name',
        'subject',
        'status',
        'skip_reason',
        'error',
        'notifiable_type',
        'notifiable_id',
        'sent_at',
        'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }
}
