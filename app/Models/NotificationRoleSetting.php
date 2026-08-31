<?php

namespace App\Models;

use App\Services\Notifications\NotificationDeliveryGate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A per-role override of one notification event's delivery settings.
 *
 * Only exists for roles an admin has explicitly configured differently from
 * the event's default — see {@see NotificationSetting} for the fallback and
 * {@see NotificationDeliveryGate} for how the two
 * combine into one decision.
 */
class NotificationRoleSetting extends Model
{
    protected $fillable = [
        'notification_setting_id',
        'role',
        'mail_enabled',
        'database_enabled',
        'is_automatic',
        'custom_subject',
        'custom_body',
    ];

    protected function casts(): array
    {
        return [
            'mail_enabled' => 'boolean',
            'database_enabled' => 'boolean',
            'is_automatic' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saved(fn () => NotificationSetting::flushCache());
        static::deleted(fn () => NotificationSetting::flushCache());
    }

    public function notificationSetting(): BelongsTo
    {
        return $this->belongsTo(NotificationSetting::class);
    }
}
