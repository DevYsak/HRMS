<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person muting an optional notification event (by class name) by email
 * and/or in-app. Mandatory events ignore it.
 */
#[Fillable(['user_id', 'notification_key', 'mail_muted', 'database_muted'])]
class NotificationPreference extends Model
{
    protected function casts(): array
    {
        return [
            'mail_muted' => 'boolean',
            'database_muted' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
