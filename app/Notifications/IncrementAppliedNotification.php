<?php

namespace App\Notifications;

use App\Models\IncrementCycle;
use App\Models\IncrementProposal;
use Illuminate\Notifications\Notification;

/**
 * In-app increment-engine events (v4 Phase E): `increment_applied` to the
 * employee, `cycle_proposed` (calibration ready) to Directors/SuperAdmins.
 */
class IncrementAppliedNotification extends Notification
{
    public function __construct(
        public readonly ?IncrementProposal $proposal,
        public readonly IncrementCycle $cycle,
        public readonly string $event = 'increment_applied',
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        if ($this->event === 'cycle_proposed') {
            return [
                'type' => 'increment_cycle_proposed',
                'title' => "Increment cycle {$this->cycle->financial_year} ready for approval",
                'body' => 'Calibration is complete and proposals are submitted. Review the budget and approve the cycle.',
                'action' => 'Open Increment Center',
                'url' => '/performance/increments',
                'icon' => 'banknotes',
                'color' => 'amber',
            ];
        }

        return [
            'type' => 'increment_applied',
            'title' => 'Your increment has been applied 🎉',
            'body' => "Your revised salary is effective {$this->cycle->effective_date->format('d M Y')} (+{$this->proposal?->proposed_percent}%). Your increment letter has been emailed to you.",
            'action' => 'View Details',
            'url' => '/performance/dashboard',
            'icon' => 'banknotes',
            'color' => 'green',
        ];
    }
}
