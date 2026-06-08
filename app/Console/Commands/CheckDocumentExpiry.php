<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Notifications\DocumentExpiryNotification;
use Illuminate\Console\Command;

class CheckDocumentExpiry extends Command
{
    protected $signature = 'hrms:check-document-expiry';

    protected $description = 'Notify HR admins of documents expiring within 30 days.';

    public function handle(): int
    {
        $expiring = Document::expiringSoon(30)
            ->whereNull('deleted_at')
            ->get();

        if ($expiring->isEmpty()) {
            $this->info('No documents expiring within 30 days.');

            return self::SUCCESS;
        }

        $hrAdmins = User::whereIn('role', ['super_admin', 'hr_admin'])->get();

        foreach ($hrAdmins as $hr) {
            $hr->notify(new DocumentExpiryNotification($expiring));
        }

        $this->info("Notified HR about {$expiring->count()} expiring document(s).");

        return self::SUCCESS;
    }
}
