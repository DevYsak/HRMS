<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationSetting;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds 'mail' channel and a standard toMail() to any Notification that
 * already implements toArray() returning title/body/action/url keys.
 *
 * Admins can override the subject/body per notification type via the
 * notification_settings table; a stamped X-Notification-Key header lets the
 * mail logger trace each message back to its event.
 */
trait SendsMailChannel
{
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $setting = NotificationSetting::for(static::class);

        $title = $data['title'] ?? config('app.name');
        $body = $data['body'] ?? '';
        $action = $data['action'] ?? 'Open';
        $url = isset($data['url']) ? url($data['url']) : url('/');

        $subject = $setting?->custom_subject ?: ($title.' — '.config('app.name'));
        $body = $setting?->custom_body ?: $body;
        $key = static::class;

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($body)
            ->action($action, $url)
            ->line('You are receiving this email because you are subscribed to notifications from '.config('app.name').'.')
            ->withSymfonyMessage(function ($message) use ($key): void {
                $message->getHeaders()->addTextHeader('X-Notification-Key', $key);
            });
    }
}
