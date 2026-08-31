<?php

namespace App\Console\Commands;

use App\Models\IncrementCycle;
use App\Models\User;
use App\Notifications\IncrementCycleReminderNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hrms:open-increment-cycle-reminder')]
#[Description('June 1: remind HR to open the July increment cycle for the upcoming Conexus financial year (v4 Phase E).')]
class OpenIncrementCycleReminder extends Command
{
    public function handle(): int
    {
        $fyStart = now()->month >= 7 ? now()->year + 1 : now()->year;
        $financialYear = $fyStart.'-'.substr((string) ($fyStart + 1), 2);

        if (IncrementCycle::where('financial_year', $financialYear)->exists()) {
            $this->info("Cycle {$financialYear} already exists — no reminder needed.");

            return self::SUCCESS;
        }

        $recipients = app(NotificationRecipients::class)->hrQueue();
        $recipients->each(fn (User $u) => $u->notify(new IncrementCycleReminderNotification($financialYear)));

        $this->info("Reminded {$recipients->count()} HR/admin user(s) to open cycle {$financialYear}.");

        return self::SUCCESS;
    }
}
