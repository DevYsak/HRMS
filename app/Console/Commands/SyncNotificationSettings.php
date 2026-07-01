<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('notifications:sync-settings')]
#[Description('Discover all notification types and seed/refresh the notification_settings table (non-destructive).')]
class SyncNotificationSettings extends Command
{
    public function handle(NotificationCatalog $catalog): int
    {
        $created = $catalog->sync();

        $this->info("Notification settings synced. {$created} new event(s) added; existing toggles preserved.");

        return self::SUCCESS;
    }
}
