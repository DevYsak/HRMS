<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Notifications extends Component
{
    public bool $open = false;

    /** Mark a single notification as read and redirect to its URL. */
    public function markRead(string $id): void
    {
        $notification = Auth::user()
            ->notifications()
            ->findOrFail($id);

        $notification->markAsRead();

        $url = $notification->data['url'] ?? null;

        if ($url) {
            $this->redirect($url);
        }
    }

    /** Mark all as read. */
    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
        $this->open = false;
    }

    public function render()
    {
        $user          = Auth::user();
        $notifications = $user->notifications()->latest()->take(15)->get();
        $unreadCount   = $user->unreadNotifications()->count();

        return view('livewire.notifications', [
            'notifications' => $notifications,
            'unreadCount'   => $unreadCount,
        ]);
    }
}
