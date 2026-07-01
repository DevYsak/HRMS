<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single hashed-password entry in a user's credential history (append-only).
 */
class PasswordHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id',
        'password',
        'changed_by',
    ];

    protected $hidden = [
        'password',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
