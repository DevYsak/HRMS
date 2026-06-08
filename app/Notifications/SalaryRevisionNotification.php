<?php

namespace App\Notifications;

use App\Models\PromotionRecommendation;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class SalaryRevisionNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly PromotionRecommendation $recommendation) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $effective = $this->recommendation->effective_date?->format('d M Y') ?? 'as notified';

        return [
            'type' => 'salary_revision',
            'title' => 'Salary Revision Approved',
            'body' => "Your salary revision has been approved, effective {$effective}. Please contact HR for further details.",
            'action' => 'View Details',
            'url' => '/performance/promotions/my',
            'icon' => 'currency-rupee',
            'color' => 'green',
        ];
    }
}
