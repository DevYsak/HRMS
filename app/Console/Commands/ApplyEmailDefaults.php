<?php

namespace App\Console\Commands;

use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;
use App\Services\Audit\AuditService;
use App\Services\Notifications\NotificationCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * D5 (8 Oct 2026): in-app is the default channel and email goes out only for
 * the events that need it (NotificationCatalog::CRITICAL_MAIL — payslip,
 * account, password reset, and since 9 Nov 2026 the leave request workflow).
 * Rows created before that may say otherwise. This brings every event to its
 * default: email OFF for every other event, email ON for those (each with its
 * role rows). Preview by default — nothing changes without --apply. HR can
 * switch any event on or off afterwards.
 */
class ApplyEmailDefaults extends Command
{
    protected $signature = 'notifications:apply-email-defaults {--apply : Write the change (default: preview only)}';

    protected $description = 'Email only for the events that need it (payslip, account, password reset, leave workflow); preview unless --apply';

    public function handle(): int
    {
        $all = NotificationSetting::with('roleSettings')->get();
        $emailOn = fn (NotificationSetting $s) => $s->mail_enabled || $s->roleSettings->contains('mail_enabled', true);
        $emailAll = fn (NotificationSetting $s) => $s->mail_enabled && ! $s->roleSettings->contains('mail_enabled', false);

        $switchOff = $all->reject(fn (NotificationSetting $s) => NotificationCatalog::isCriticalMail($s->key))->filter($emailOn)->values();
        $switchOn = $all->filter(fn (NotificationSetting $s) => NotificationCatalog::isCriticalMail($s->key))->reject($emailAll)->values();

        if ($switchOff->isEmpty() && $switchOn->isEmpty()) {
            $this->info('Every event already follows the email defaults. Nothing to change.');

            return self::SUCCESS;
        }

        $row = fn (NotificationSetting $s, string $change) => [
            $s->label ?: class_basename($s->key),
            $s->group,
            $s->mail_enabled ? 'on' : 'off',
            $s->roleSettings->where('mail_enabled', true)->pluck('role')->implode(', ') ?: '—',
            $change,
        ];
        $this->table(['Event', 'Group', 'Email now', 'Role rows with email on', 'Change'],
            $switchOn->toBase()->map(fn ($s) => $row($s, 'email ON'))->merge($switchOff->toBase()->map(fn ($s) => $row($s, 'email off')))->all());

        if (! $this->option('apply')) {
            $this->warn($switchOff->count().' event(s) would have email switched off and '.$switchOn->count().' switched on. Run again with --apply to write it. In-app delivery is not affected.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($switchOff, $switchOn) {
            foreach ([[$switchOff, false], [$switchOn, true]] as [$settings, $enabled]) {
                foreach ($settings as $setting) {
                    $setting->update(['mail_enabled' => $enabled]);
                    NotificationRoleSetting::where('notification_setting_id', $setting->id)->update(['mail_enabled' => $enabled]);
                }
            }
        });

        app(AuditService::class)->event('NOTIFICATION_EMAIL_DEFAULTS_APPLIED', AuditService::SETTINGS, $switchOff->first() ?? $switchOn->first(),
            new: ['email_switched_off' => $switchOff->pluck('key')->all(), 'email_switched_on' => $switchOn->pluck('key')->all()],
            reason: 'D5: email only for payslip, account, password reset and the leave workflow');

        NotificationSetting::flushCache();

        $this->info($switchOn->count().' event(s) now email, '.$switchOff->count().' event(s) now in-app only.');

        return self::SUCCESS;
    }
}
