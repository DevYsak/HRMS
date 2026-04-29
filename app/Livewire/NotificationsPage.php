<?php

namespace App\Livewire;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Livewire\WithPagination;

class NotificationsPage extends Component
{
    use WithPagination;

    public string $filter = 'all';     // all | unread | read

    public string $typeFilter = '';

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
    }

    public function setType(string $type): void
    {
        $this->typeFilter = $this->typeFilter === $type ? '' : $type;
        $this->resetPage();
    }

    public function markRead(string $id): void
    {
        Auth::user()->notifications()->findOrFail($id)->markAsRead();
    }

    public function markAllRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function delete(string $id): void
    {
        Auth::user()->notifications()->findOrFail($id)->delete();
    }

    public function clearRead(): void
    {
        Auth::user()->notifications()->whereNotNull('read_at')->delete();
    }

    public function redirectTo(string $id): void
    {
        $notif = Auth::user()->notifications()->findOrFail($id);
        $notif->markAsRead();
        $url = $notif->data['url'] ?? null;
        if ($url) {
            $this->redirect($url);
        }
    }

    public function render()
    {
        $user = Auth::user();
        $query = $user->notifications()->latest();

        if ($this->filter === 'unread') {
            $query->whereNull('read_at');
        } elseif ($this->filter === 'read') {
            $query->whereNotNull('read_at');
        }

        if ($this->typeFilter) {
            $query->where('data->type', $this->typeFilter);
        }

        // Distinct notification types for the filter pills
        $allTypes = $user->notifications()
            ->get()
            ->pluck('data.type')
            ->unique()
            ->filter()
            ->values();

        return view('livewire.notifications-page', [
            'notifications' => $query->paginate(20),
            'unreadCount' => $user->unreadNotifications()->count(),
            'allTypes' => $allTypes,
        ])->layout('layouts.app', ['title' => 'Notifications']);
    }
}
