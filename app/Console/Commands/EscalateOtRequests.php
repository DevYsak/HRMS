<?php

namespace App\Console\Commands;

use App\Models\OtRequest;
use App\Notifications\OtRequestNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;

/**
 * Spec §7 — OT requests pending more than 24 hours are escalated to HR Admin
 * in-app. Runs hourly; each request is escalated ONCE (escalated_at), not
 * re-announced every hour for as long as it stays pending.
 */
class EscalateOtRequests extends Command
{
    protected $signature = 'hrms:escalate-ot';

    protected $description = 'Escalate OT requests pending more than 24 hours to HR admins (once per request).';

    public function handle(): int
    {
        $cutoff = now()->subHours(24);

        $pending = OtRequest::with(['employee.user'])
            ->where('status', 'pending')
            ->whereNull('escalated_at')
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No OT requests require escalation.');

            return self::SUCCESS;
        }

        $hrAdmins = app(NotificationRecipients::class)->hrQueue();
        $escalated = 0;

        foreach ($pending as $request) {
            try {
                foreach ($hrAdmins as $hr) {
                    $hr->notify((new OtRequestNotification($request))->forRole('hr_admin'));
                }

                $request->forceFill(['escalated_at' => now()])->save();
                $escalated++;

                $this->line("Escalated OT request #{$request->id} for {$request->employee?->user?->name}");
            } catch (\Throwable $e) {
                // One failure never blocks the rest; it is retried next hour.
                report($e);
            }
        }

        $this->info("Escalated {$escalated} OT request(s).");

        return self::SUCCESS;
    }
}
