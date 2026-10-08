<?php

namespace App\Console\Commands;

use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D5 (8 Oct 2026): in-app is the default channel and non-critical email is
 * off by default. New settings rows already follow that; rows created before
 * it still have email switched on. This switches email off on every
 * non-critical event (and its role rows). Preview by default — nothing
 * changes without --apply. HR can switch any event back on afterwards.
 */
class ApplyEmailDefaults extends Command
{
    protected $signature = 'notifications:apply-email-defaults {--apply : Write the change (default: preview only)}';

    protected $description = 'Switch email off for non-critical notification events (D5); preview unless --apply';

    public function handle(): int
    {
        $settings = NotificationSetting::with('roleSettings')->get()
            ->reject(fn (NotificationSetting $s) => NotificationCatalog::isCriticalMail($s->key))
            ->filter(fn (NotificationSetting $s) => $s->mail_enabled || $s->roleSettings->contains('mail_enabled', true))
            ->values();

        if ($settings->isEmpty()) {
            $this->info('Every non-critical event already has email off. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Event', 'Group', 'Email now', 'Role rows with email on'], $settings->map(fn (NotificationSetting $s) => [
            $s->label ?: class_basename($s->key),
            $s->group,
            $s->mail_enabled ? 'on' : 'off',
            $s->roleSettings->where('mail_enabled', true)->pluck('role')->implode(', ') ?: '—',
        ])->all());

        if (! $this->option('apply')) {
            $this->warn($settings->count().' event(s) would have email switched off. Run again with --apply to write it. In-app delivery is not affected.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($settings) {
            foreach ($settings as $setting) {
                $setting->update(['mail_enabled' => false]);
                NotificationRoleSetting::where('notification_setting_id', $setting->id)->update(['mail_enabled' => false]);
            }
        });

        app(AuditService::class)->event('NOTIFICATION_EMAIL_DEFAULTS_APPLIED', AuditService::SETTINGS, $settings->first(),
            new: ['email_switched_off' => $settings->pluck('key')->all()], reason: 'D5: in-app by default, non-critical email off');

        NotificationSetting::flushCache();

        $this->info($settings->count().' event(s) now in-app only. Critical email (payslip, account, password reset) is unchanged.');

        return self::SUCCESS;
    }
}
