<div class="relative" x-data="{ open: false }" @click.outside="open = false">

    {{-- Bell Button --}}
    <button
        @click="open = !open"
        class="relative flex items-center justify-center size-9 rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 transition-colors dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white"
        aria-label="Notifications"
    >
        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round"
                d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
        </svg>

        @if($unreadCount > 0)
            <span class="absolute top-1 right-1 flex size-4 items-center justify-center rounded-full bg-red-500 text-[9px] font-bold text-white leading-none">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown Panel --}}
    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 scale-95 translate-y-1"
        x-transition:enter-end="opacity-100 scale-100 translate-y-0"
        x-transition:leave="transition ease-in duration-100"
        x-transition:leave-start="opacity-100 scale-100 translate-y-0"
        x-transition:leave-end="opacity-0 scale-95 translate-y-1"
        class="absolute right-0 top-11 z-50 w-80 rounded-xl border border-zinc-100 bg-white shadow-xl dark:border-zinc-800 dark:bg-zinc-900"
        style="display:none"
    >
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
            <span class="text-sm font-semibold text-zinc-900 dark:text-white">
                Notifications
                @if($unreadCount > 0)
                    <span class="ml-1.5 rounded-full bg-red-100 px-2 py-0.5 text-xs font-bold text-red-600 dark:bg-red-900/40 dark:text-red-400">
                        {{ $unreadCount }} new
                    </span>
                @endif
            </span>
            @if($unreadCount > 0)
                <button
                    wire:click="markAllRead"
                    class="text-xs text-blue-600 hover:underline dark:text-blue-400"
                >
                    Mark all read
                </button>
            @endif
        </div>

        {{-- List --}}
        <div class="max-h-96 overflow-y-auto divide-y divide-zinc-50 dark:divide-zinc-800/60">
            @forelse($notifications as $notif)
                @php $data = $notif->data; $isUnread = is_null($notif->read_at); @endphp
                <button
                    wire:click="markRead('{{ $notif->id }}')"
                    class="flex w-full items-start gap-3 px-4 py-3 text-left transition-colors hover:bg-zinc-50 dark:hover:bg-zinc-800/50 {{ $isUnread ? 'bg-blue-50/40 dark:bg-blue-950/20' : '' }}"
                >
                    {{-- Icon dot --}}
                    <span class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full
                        {{ match($data['color'] ?? 'blue') {
                            'green' => 'bg-green-100 text-green-600 dark:bg-green-900/30 dark:text-green-400',
                            'red'   => 'bg-red-100 text-red-600 dark:bg-red-900/30 dark:text-red-400',
                            'amber' => 'bg-amber-100 text-amber-600 dark:bg-amber-900/30 dark:text-amber-400',
                            default => 'bg-blue-100 text-blue-600 dark:bg-blue-900/30 dark:text-blue-400',
                        } }}">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                            @switch($data['icon'] ?? 'bell')
                                @case('check-circle')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                    @break
                                @case('x-circle')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                    @break
                                @case('banknotes')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75" />
                                    @break
                                @case('clock')
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                    @break
                                @default
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                            @endswitch
                        </svg>
                    </span>

                    {{-- Text --}}
                    <div class="flex-1 min-w-0">
                        <p class="text-xs font-semibold text-zinc-900 dark:text-white {{ $isUnread ? '' : 'opacity-70' }}">
                            {{ $data['title'] ?? '' }}
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                            {{ $data['body'] ?? '' }}
                        </p>
                        <p class="mt-1 text-[10px] text-zinc-400">
                            {{ $notif->created_at->diffForHumans() }}
                        </p>
                    </div>

                    @if($isUnread)
                        <span class="mt-2 size-1.5 shrink-0 rounded-full bg-blue-500"></span>
                    @endif
                </button>
            @empty
                <div class="py-10 text-center text-sm text-zinc-400">
                    <svg class="mx-auto mb-2 size-8 opacity-30" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                    </svg>
                    No notifications yet
                </div>
            @endforelse
        </div>
    </div>
</div>
