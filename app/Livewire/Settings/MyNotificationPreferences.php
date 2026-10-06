<?php

namespace App\Livewire\Settings;

use App\Models\NotificationPreference;
use App\Models\NotificationSetting;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Settings → My notifications: a person mutes the email and / or in-app
 * notice of optional events. Mandatory events (set by HR on the
 * Notifications & Email page) are shown but cannot be muted, and an event an
 * administrator has switched off stays off whatever is chosen here.
 */
class MyNotificationPreferences extends Component
{
    public function toggle(int $settingId, string $channel): void
    {
        abort_unless(in_array($channel, ['mail', 'database'], true), 422);

        $setting = NotificationSetting::findOrFail($settingId);

        if ($setting->is_mandatory) {
            \Flux::toast('This notification is required by HR and cannot be turned off.', variant: 'warning');

            return;
        }

        $preference = NotificationPreference::firstOrNew(['user_id' => Auth::id(), 'notification_key' => $setting->key]);
        $field = $channel === 'mail' ? 'mail_muted' : 'database_muted';
        $preference->{$field} = ! $preference->{$field};
        $preference->save();
    }

    public function render()
    {
        $preferences = NotificationPreference::where('user_id', Auth::id())->get()->keyBy('notification_key');

        $settings = NotificationSetting::query()
            ->where(fn ($q) => $q->where('mail_enabled', true)->orWhere('database_enabled', true))
            ->orderBy('sort_order')->orderBy('label')
            ->get()
            ->groupBy('group');

        return view('livewire.settings.my-notification-preferences', compact('settings', 'preferences'))
            ->layout('layouts.app', ['title' => 'My notifications']);
    }
}
