<?php

namespace App\Console\Commands;

use App\Models\OtRequest;
use App\Notifications\OtRequestNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;

class EscalateOtRequests extends Command
{
    protected $signature = 'hrms:escalate-ot';

    protected $description = 'Escalate OT requests pending more than 24 hours to HR admins.';

    public function handle(): int
    {
        $cutoff = now()->subHours(24);

        $pending = OtRequest::with(['employee.user'])
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No OT requests require escalation.');

            return self::SUCCESS;
        }

        $hrAdmins = app(NotificationRecipients::class)->hrQueue();

        foreach ($pending as $request) {
            foreach ($hrAdmins as $hr) {
                $hr->notify((new OtRequestNotification($request))->forRole('hr_admin'));
            }

            $this->line("Escalated OT request #{$request->id} for {$request->employee->user->name}");
        }

        $this->info("Escalated {$pending->count()} OT request(s).");

        return self::SUCCESS;
    }
}
