<?php

use Livewire\Component;
use Illuminate\Support\Facades\Auth;

new class extends Component
{
    public function getNotificationsProperty()
    {
        return Auth::user()->unreadNotifications;
    }

    public function markAsRead($notificationId)
    {
        $notification = Auth::user()->notifications()->find($notificationId);
        if ($notification) {
            $notification->markAsRead();
        }
    }

    public function markAllAsRead()
    {
        Auth::user()->unreadNotifications->markAsRead();
    }
};
?>

<flux:dropdown position="bottom" align="end">
    <flux:button variant="ghost" size="sm" icon="bell" class="relative text-zinc-500 dark:text-zinc-400">
        @if($this->notifications->count() > 0)
            <span class="absolute top-1.5 right-1.5 size-1.5 rounded-full bg-brand-600 animate-pulse"></span>
        @endif
    </flux:button>
    <flux:menu class="w-80">
        <div class="flex items-center justify-between px-3 py-2 border-b border-zinc-100 dark:border-zinc-800">
            <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wide">{{ __('Notifications') }}</span>
            @if($this->notifications->count() > 0)
                <button wire:click.stop="markAllAsRead" class="text-[10px] uppercase font-bold tracking-widest text-brand-600 hover:underline">Mark all read</button>
            @endif
        </div>
        
        <div class="max-h-72 overflow-y-auto">
            @forelse($this->notifications as $notification)
                <div class="px-3 py-3 border-b border-zinc-50 dark:border-zinc-800/50 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors flex gap-3 relative group">
                    <div class="flex-1 min-w-0">
                        <p class="text-sm text-zinc-800 dark:text-zinc-200">
                            {{ $notification->data['message'] ?? 'New notification' }}
                        </p>
                        <p class="text-xs text-zinc-400 mt-1">
                            {{ $notification->created_at->diffForHumans() }}
                        </p>
                    </div>
                    <div class="shrink-0 pt-1">
                        <button wire:click.stop="markAsRead('{{ $notification->id }}')" class="opacity-0 group-hover:opacity-100 transition-opacity text-brand-600 hover:text-brand-700">
                            <flux:icon.check-circle class="size-4" />
                        </button>
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center bg-zinc-50/50 dark:bg-zinc-900/20">
                    <flux:icon.bell-snooze class="size-8 text-zinc-300 mx-auto mb-2 dark:text-zinc-700" />
                    <p class="text-sm text-zinc-400">{{ __('You\'re all caught up!') }}</p>
                </div>
            @endforelse
        </div>
    </flux:menu>
</flux:dropdown>