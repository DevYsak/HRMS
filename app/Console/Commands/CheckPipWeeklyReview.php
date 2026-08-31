<?php

namespace App\Console\Commands;

use App\Models\PipRecord;
use App\Models\User;
use App\Notifications\PipWeeklyReviewDueNotification;
use Illuminate\Console\Command;

class CheckPipWeeklyReview extends Command
{
    protected $signature = 'hrms:check-pip-weekly-review';

    protected $description = 'Send weekly progress review reminders for all active PIPs to manager, HR admin, and employee.';

    public function handle(): int
    {
        $pips = PipRecord::with(['employee.user', 'manager', 'hrReviewer'])
            ->where('status', 'active')
            ->get();

        if ($pips->isEmpty()) {
            $this->info('No active PIPs requiring weekly review.');

            return self::SUCCESS;
        }

        $notified = 0;

        foreach ($pips as $pip) {
            $recipients = collect();

            // Employee
            if ($pip->employee->user) {
                $recipients->push($pip->employee->user);
            }

            // Manager
            if ($pip->manager instanceof User) {
                $recipients->push($pip->manager);
            }

            // HR reviewer
            if ($pip->hrReviewer instanceof User) {
                $recipients->push($pip->hrReviewer);
            }

            foreach ($recipients->unique('id') as $user) {
                $role = match (true) {
                    $pip->manager instanceof User && $user->id === $pip->manager->id => 'manager',
                    $pip->hrReviewer instanceof User && $user->id === $pip->hrReviewer->id => 'hr_admin',
                    default => 'employee',
                };
                $user->notify((new PipWeeklyReviewDueNotification($pip))->forRole($role));
                $notified++;
            }
        }

        $this->info("Sent {$notified} weekly PIP review notification(s) across {$pips->count()} active PIP(s).");

        return self::SUCCESS;
    }
}
