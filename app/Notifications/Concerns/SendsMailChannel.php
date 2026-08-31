<?php

namespace App\Notifications\Concerns;

use App\Models\NotificationSetting;
use App\Services\Notifications\TemplateVariableRenderer;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Adds 'mail' channel and a standard toMail() to any Notification that
 * already implements toArray() returning title/body/action/url keys.
 *
 * Admins can override the subject/body per notification type via the
 * notification_settings table, and per role via notification_role_settings
 * when {@see NotifiesByRole} is also used — a role-specific template wins
 * over the event-level one, which wins over the class default. A class that
 * also implements templateVariables() gets {{token}} substitution in its
 * custom subject/body; without it, a saved custom_body is used verbatim, as
 * it always has been.
 *
 * A stamped X-Notification-Key (and, when role-aware, X-Notification-Role)
 * header lets the mail logger and the delivery gate trace each message back
 * to its event and recipient role at the point it actually transports —
 * the final, authoritative check, not just the one made when the
 * notification was queued.
 */
trait SendsMailChannel
{
    public function toMail(object $notifiable): MailMessage
    {
        $data = $this->toArray($notifiable);
        $key = static::class;
        $role = method_exists($this, 'notificationRole') ? $this->notificationRole() : null;

        $setting = NotificationSetting::for($key);
        $roleSetting = $setting?->roleSetting($role);

        $title = $data['title'] ?? config('app.name');
        $action = $data['action'] ?? 'Open';
        $url = isset($data['url']) ? url($data['url']) : url('/');

        $customSubject = $roleSetting?->custom_subject ?: $setting?->custom_subject;
        $customBody = $roleSetting?->custom_body ?: $setting?->custom_body;

        if (method_exists($this, 'templateVariables')) {
            $renderer = app(TemplateVariableRenderer::class);
            $vars = $this->templateVariables($notifiable);
            $customSubject = $customSubject !== null ? $renderer->render($customSubject, $vars) : null;
            $customBody = $customBody !== null ? $renderer->render($customBody, $vars) : null;
        }

        $subject = $customSubject ?: ($title.' — '.config('app.name'));
        $body = $customBody ?: ($data['body'] ?? '');

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.($notifiable->name ?? 'there').',')
            ->line($body)
            ->action($action, $url)
            ->line('You are receiving this email because you are subscribed to notifications from '.config('app.name').'.')
            ->withSymfonyMessage(function ($message) use ($key, $role): void {
                $message->getHeaders()->addTextHeader('X-Notification-Key', $key);

                if ($role !== null) {
                    $message->getHeaders()->addTextHeader('X-Notification-Role', $role);
                }
            });
    }
}
