<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Notifications extends Component
{
    /** Notifications shown in the bell dropdown. */
    public const PREVIEW_LIMIT = 5;

    public bool $open = false;

    public function markRead(string $id): void
    {
        $notification = Auth::user()->notifications()->findOrFail($id);
        $notification->markAsRead();
        Cache::forget('notif_count_'.Auth::id());

        $url = $notification->data['url'] ?? null;
        if ($url) {
            $this->redirect($url, navigate: true);
        }
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
        Cache::forget('notif_count_'.Auth::id());
        $this->open = false;
    }

    public function render()
    {
        $user = Auth::user();

        $unreadCount = Cache::remember('notif_count_'.$user->id, 30, fn () => $user->unreadNotifications()->count());

        // A short preview; "View all notifications" opens the Notifications page.
        $notifications = $user->notifications()->latest()->take(self::PREVIEW_LIMIT)->get();

        return view('livewire.notifications', compact('notifications', 'unreadCount'));
    }
}
