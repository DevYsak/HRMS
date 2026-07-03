<?php

namespace App\Models;

use App\Providers\AppServiceProvider;
use Illuminate\Database\Eloquent\Model;

/**
 * Single-row, database-driven master switch for all outgoing email.
 *
 * When {@see self::$mail_enabled} is false, the MessageSending listener in
 * {@see AppServiceProvider} cancels every outgoing message — a
 * global kill switch that covers transactional notifications AND directly-sent
 * Mailables. Use {@see self::current()} to read the live configuration.
 */
class MailSetting extends Model
{
    protected $fillable = [
        'mail_enabled',
    ];

    protected $casts = [
        'mail_enabled' => 'boolean',
    ];

    /**
     * The single configuration row, created enabled on first use.
     */
    public static function current(): self
    {
        return static::query()->first() ?? static::query()->create([
            'mail_enabled' => true,
        ]);
    }

    /**
     * Whether outgoing email is globally enabled.
     */
    public static function mailEnabled(): bool
    {
        return static::current()->mail_enabled;
    }
}
