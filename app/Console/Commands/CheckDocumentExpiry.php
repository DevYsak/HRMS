<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Notifications\DocumentExpiryNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;

/**
 * Spec §3.9 / §7 — notify HR Admin in-app of documents expiring within 30
 * days. Each document is announced once per expiry date
 * (expiry_notified_for): the daily run used to re-send the same digest every
 * day of the 30-day window. A renewed document (new expiry date) is
 * announced again when it comes due.
 */
class CheckDocumentExpiry extends Command
{
    protected $signature = 'hrms:check-document-expiry';

    protected $description = 'Notify HR admins (once per expiry date) of documents expiring within 30 days.';

    public function handle(): int
    {
        // query(): Document also has an instance method expiringSoon(): bool,
        // so the static call hit that method and fataled — the job never ran.
        $expiring = Document::query()->expiringSoon(30)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('expiry_notified_for')->orWhereColumn('expiry_notified_for', '!=', 'expires_at'))
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No newly expiring documents within 30 days.');

            return self::SUCCESS;
        }

        $hrAdmins = app(NotificationRecipients::class)->hrQueue();

        foreach ($hrAdmins as $hr) {
            $hr->notify(new DocumentExpiryNotification($expiring));
        }

        foreach ($expiring as $document) {
            $document->forceFill(['expiry_notified_for' => $document->expires_at])->saveQuietly();
        }

        $this->info("Notified HR about {$expiring->count()} expiring document(s).");

        return self::SUCCESS;
    }
}
