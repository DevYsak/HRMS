<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds 'mail' channel and a standard toMail() to any Notification that
 * already implements toArray() returning title/body/action/url keys.
 */
trait SendsMailChannel
{
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);

        $title = $data['title'] ?? config('app.name');
        $body = $data['body'] ?? '';
        $action = $data['action'] ?? 'Open';
        $url = isset($data['url']) ? url($data['url']) : url('/');

        return (new MailMessage)
            ->subject($title.' — '.config('app.name'))
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($body)
            ->action($action, $url)
            ->line('You are receiving this email because you are subscribed to notifications from '.config('app.name').'.');
    }
}
