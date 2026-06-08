<?php

namespace App\Notifications;

use App\Models\PromotionRecommendation;
use App\Notifications\Concerns\SendsMailChannel;
use Illuminate\Notifications\Notification;

class BonusAwardedNotification extends Notification
{
    use SendsMailChannel;

    public function __construct(public readonly PromotionRecommendation $recommendation) {}

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toArray(object $notifiable): array
    {
        $amount = $this->recommendation->bonus_amount
            ? number_format((float) $this->recommendation->bonus_amount, 2)
            : 'an amount';
        $effective = $this->recommendation->effective_date?->format('d M Y') ?? 'as notified';

        return [
            'type' => 'bonus_awarded',
            'title' => 'Bonus Awarded',
            'body' => "Congratulations! A bonus of ₹{$amount} has been approved for you, effective {$effective}.",
            'action' => 'View Details',
            'url' => '/performance/promotions/my',
            'icon' => 'gift',
            'color' => 'green',
        ];
    }
}
