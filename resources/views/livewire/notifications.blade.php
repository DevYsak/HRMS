<div class="relative" x-data="{ open: false }" @click.outside="open = false" wire:poll.30s>

    {{-- Bell Button --}}
    <button @click="open = !open"
        class="relative flex size-9 cursor-pointer items-center justify-center rounded-lg text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
        aria-label="Notifications">
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>
        @if($unreadCount > 0)
            <span class="absolute right-1 top-1 flex size-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-black leading-none text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown --}}
    <div x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute right-0 top-11 z-50 w-96 overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-xl dark:border-zinc-800 dark:bg-zinc-900"
        style="display:none">

        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
            <div class="flex items-center gap-2">
                <span class="text-sm font-black text-zinc-900 dark:text-white">Notifications</span>
                @if($unreadCount > 0)
                    <span class="rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-black text-red-600 dark:bg-red-900/30 dark:text-red-400">
                        {{ $unreadCount }} new
                    </span>
                @endif
            </div>
            @if($unreadCount > 0)
                <button wire:click="markAllRead" class="cursor-pointer text-xs font-bold text-blue-600 hover:text-blue-700 hover:underline dark:text-blue-400">
                    Mark all read
                </button>
            @endif
        </div>

        {{-- List --}}
        <div class="max-h-[420px] divide-y divide-zinc-50 overflow-y-auto dark:divide-zinc-800/60">
            @forelse($notifications as $notif)
                @php
                    $data     = $notif->data;
                    $isUnread = is_null($notif->read_at);
                    $color    = $data['color'] ?? 'blue';
                    $icon     = $data['icon'] ?? 'bell';
                    $iconBg   = match($color) {
                        'green'  => 'bg-emerald-100 text-emerald-600 dark:bg-emerald-900/30 dark:text-emerald-400',
                        'red'    => 'bg-rose-100 text-rose-600 dark:bg-rose-900/30 dark:text-rose-400',
                        'amber'  => 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
                        'orange' => 'bg-orange-100 text-orange-600 dark:bg-orange-900/30 dark:text-orange-400',
                        'purple' => 'bg-violet-100 text-violet-600 dark:bg-violet-900/30 dark:text-violet-400',
                        default  => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
                    };
                @endphp
                <button wire:click="markRead('{{ $notif->id }}')"
                    class="flex w-full cursor-pointer items-start gap-3 px-4 py-3.5 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50
                        {{ $isUnread ? 'bg-blue-50/50 dark:bg-blue-950/10' : '' }}">

                    {{-- Icon --}}
                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl {{ $iconBg }}">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            @switch($icon)
                                @case('check-circle')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />@break
                                @case('x-circle')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />@break
                                @case('banknotes')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75" />@break
                                @case('receipt-refund')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9.75h4.875a2.625 2.625 0 0 1 0 5.25H12M8.25 9.75 10.5 7.5M8.25 9.75 10.5 12m9-7.243V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185z" />@break
                                @case('currency-rupee')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 8.25H9m6 3H9m3 6-3-3h1.5a3 3 0 1 0 0-6M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />@break
                                @case('clock')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />@break
                                @case('calendar')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />@break
                                @case('document-text')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z" />@break
                                @case('clipboard-document-check')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" />@break
                                @case('exclamation-triangle')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />@break
                                @case('user-check')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />@break
                                @default
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                            @endswitch
                        </svg>
                    </span>

                    {{-- Text --}}
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-bold text-zinc-900 dark:text-white {{ $isUnread ? '' : 'opacity-70' }}">
                            {{ $data['title'] ?? '' }}
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                            {{ $data['body'] ?? $data['message'] ?? '' }}
                        </p>
                        <p class="mt-1 text-[10px] text-zinc-400">{{ $notif->created_at->diffForHumans() }}</p>
                    </div>

                    {{-- Unread dot --}}
                    @if($isUnread)
                        <span class="mt-2 size-2 shrink-0 rounded-full bg-blue-500"></span>
                    @endif
                </button>
            @empty
                <div class="flex flex-col items-center py-12 text-center">
                    <svg class="mx-auto mb-2 size-9 text-zinc-200 dark:text-zinc-700" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                    </svg>
                    <p class="text-sm font-medium text-zinc-400">No notifications yet</p>
                </div>
            @endforelse
        </div>

        {{-- Footer --}}
        <div class="border-t border-zinc-100 px-4 py-3 dark:border-zinc-800">
            <a href="{{ route('notifications.index') }}" wire:navigate
                class="flex w-full cursor-pointer items-center justify-center gap-1.5 text-xs font-bold text-zinc-500 transition-colors hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white">
                View all notifications
                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    </div>
</div>
