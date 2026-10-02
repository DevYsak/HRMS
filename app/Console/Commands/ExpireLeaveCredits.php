<?php

namespace App\Console\Commands;

use App\Services\Leave\LeaveExpiryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leave:expire-credits {--notify-only : Send expiry notices without expiring anything}')]
#[Description('Expire unused carry-forward/add-on lots past their expiry date and send 30/7-day expiry notices')]
class ExpireLeaveCredits extends Command
{
    public function handle(LeaveExpiryService $expiry): int
    {
        if (! $this->option('notify-only')) {
            $result = $expiry->expireDue();
            $this->info("Expired {$result['expired_lots']} lot(s), {$result['expired_days']} day(s); {$result['failed']} failed.");
        }

        $this->info('Sent '.$expiry->notifyUpcoming().' expiry notice(s).');

        return self::SUCCESS;
    }
}
