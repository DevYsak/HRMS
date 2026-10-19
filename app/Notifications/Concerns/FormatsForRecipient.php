<?php

namespace App\Notifications\Concerns;

use App\Models\User;
use App\Support\DateDisplay;

/**
 * Dates and times in a notification in the RECIPIENT's preferences (date
 * format, 12/24-hour clock) — not the sender's, and not a hardcoded
 * "M d, Y" one notification and "d M Y" the next (Phase 4). Formatted once,
 * when the notification is stored.
 */
trait FormatsForRecipient
{
    protected function recipientDateFormat(object $notifiable): string
    {
        return DateDisplay::for($notifiable instanceof User ? $notifiable : null)->dateFormat();
    }

    protected function recipientTimeFormat(object $notifiable): string
    {
        return DateDisplay::for($notifiable instanceof User ? $notifiable : null)->timeFormat();
    }
}
