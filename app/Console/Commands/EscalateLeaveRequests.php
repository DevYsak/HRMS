<?php

namespace App\Console\Commands;

use App\Models\LeaveEscalation;
use App\Models\LeaveRequest;
use App\Notifications\LeaveRequestNotification;
use App\Services\Notifications\NotificationRecipients;
use Illuminate\Console\Command;

class EscalateLeaveRequests extends Command
{
    protected $signature = 'hrms:escalate-leaves';

    protected $description = 'Escalate leave requests pending > 24 hours to HR admins.';

    public function handle(): int
    {
        $cutoff = now()->subHours(24);

        $pending = LeaveRequest::with(['employee.user', 'leaveType'])
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('escalations', fn ($q) => $q->where('resolved', false))
            ->get();

        if ($pending->isEmpty()) {
            $this->info('No pending requests require escalation.');

            return self::SUCCESS;
        }

        // Escalation goes to HR as a queue: the point is that the manager did
        // not respond, so it needs whoever is available, not another individual.
        $hrAdmins = app(NotificationRecipients::class)->hrQueue();

        foreach ($pending as $request) {
            foreach ($hrAdmins as $hr) {
                LeaveEscalation::create([
                    'leave_request_id' => $request->id,
                    'escalated_to' => $hr->id,
                    'reason' => 'No manager response within 24 hours.',
                    'escalated_at' => now(),
                ]);

                $hr->notify((new LeaveRequestNotification($request))->forRole('hr_admin'));
            }

            $this->line("Escalated leave request #{$request->id} for {$request->employee->user->name}");
        }

        $this->info("Escalated {$pending->count()} request(s).");

        return self::SUCCESS;
    }
}
