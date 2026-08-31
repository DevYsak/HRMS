<?php

namespace App\Services\Notifications;

use App\Models\NotificationRoleSetting;
use App\Models\NotificationSetting;

/**
 * The single decision point for whether one channel of one notification
 * event may fire, for one recipient role.
 *
 * Every mail-sending path in the app — the notification system's mail
 * channel, and every directly-sent Mailable — funnels through {@see mail()}
 * at the point the message actually transports (Illuminate\Mail\Events\
 * MessageSending), so a setting change is honoured even for a message that
 * was queued before it changed. {@see database()} governs the in-app
 * channel and is checked at Illuminate\Notifications\Events\NotificationSending.
 *
 * Resolution order for both channels: a role-specific override if one is
 * configured for this event, otherwise the event's own settings, otherwise —
 * no row at all — fail open. An event nobody has ever configured, or a role
 * nobody has ever configured within an otherwise-configured event, behaves
 * exactly as it did before either settings layer existed.
 */
class NotificationDeliveryGate
{
    /**
     * $manual marks a message a person explicitly triggered right now (e.g. a
     * "Resend" button), as opposed to the system dispatching it on its own —
     * Auto-Send governs only the latter, per spec: "the event may still
     * exist/log internally, but must NOT automatically send email." A manual
     * send still respects mail_enabled and the global pause.
     */
    public function mail(string $eventKey, ?string $role, bool $manual = false): DeliveryDecision
    {
        $resolved = $this->resolve($eventKey, $role);

        if ($resolved === null) {
            return DeliveryDecision::allow();
        }

        if (! $resolved->mail_enabled) {
            return DeliveryDecision::skip('notification_email_disabled');
        }

        if (! $manual && ! $resolved->is_automatic) {
            return DeliveryDecision::skip('auto_send_disabled');
        }

        return DeliveryDecision::allow();
    }

    public function database(string $eventKey, ?string $role): DeliveryDecision
    {
        $resolved = $this->resolve($eventKey, $role);

        if ($resolved === null) {
            return DeliveryDecision::allow();
        }

        if (! $resolved->database_enabled) {
            return DeliveryDecision::skip('notification_disabled');
        }

        return DeliveryDecision::allow();
    }

    /**
     * The effective settings for this event+role: a role override when one
     * exists, else the event's own row, else null (nothing configured).
     */
    private function resolve(string $eventKey, ?string $role): NotificationSetting|NotificationRoleSetting|null
    {
        $setting = NotificationSetting::for($eventKey);

        if ($setting === null) {
            return null;
        }

        return $setting->roleSetting($role) ?? $setting;
    }
}
