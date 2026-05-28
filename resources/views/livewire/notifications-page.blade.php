<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    @php
    /**
     * Helper: returns [bg_class, text_class, dot_class] for a notification color string.
     */
    function notifColorClasses(string $color = 'blue'): array {
        return match($color) {
            'green'  => ['bg-emerald-50 dark:bg-emerald-950/20', 'text-emerald-600 dark:text-emerald-400', 'bg-emerald-500'],
            'red'    => ['bg-rose-50 dark:bg-rose-950/20',    'text-rose-600 dark:text-rose-400',    'bg-rose-500'],
            'amber'  => ['bg-amber-50 dark:bg-amber-950/20',  'text-amber-600 dark:text-amber-400',  'bg-amber-500'],
            'orange' => ['bg-orange-50 dark:bg-orange-950/20','text-orange-600 dark:text-orange-400','bg-brand-600'],
            'purple' => ['bg-violet-50 dark:bg-violet-950/20','text-violet-600 dark:text-violet-400','bg-violet-500'],
            'zinc'   => ['bg-zinc-100 dark:bg-zinc-800',      'text-zinc-500 dark:text-zinc-400',    'bg-zinc-400'],
            default  => ['bg-blue-50 dark:bg-blue-950/20',    'text-blue-600 dark:text-blue-400',    'bg-blue-500'],
        };
    }

    function notifTypeLabel(string $type): string {
        return match($type) {
            'leave_request'            => 'Leave',
            'ot_request'               => 'Overtime',
            'attendance_regularisation'=> 'Attendance',
            'payroll'                  => 'Payroll',
            'payslip'                  => 'Payslip',
            'leave_encashment'         => 'Encashment',
            'excess_break'             => 'Break',
            'missing_checkout'         => 'Checkout',
            'probation_due'            => 'Probation',
            'probation_extended'       => 'Probation',
            'review_reminder'          => 'Review',
            'document_expiry'          => 'Documents',
            'newhire_checkin'          => 'Onboarding',
            'expense_claim'            => 'Expense',
            'incentive'                => 'Incentive',
            'reimbursement'            => 'Reimbursement',
            default                    => ucfirst(str_replace('_', ' ', $type)),
        };
    }
    @endphp

    {{-- ─── HERO HEADER ─── --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-zinc-800 via-zinc-900 to-zinc-950 p-7 shadow-xl">
        <div class="pointer-events-none absolute -top-12 -right-12 size-48 rounded-full bg-white/5 blur-3xl"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.04]" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-5">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/10 px-3 py-1">
                    <div class="size-1.5 rounded-full bg-zinc-300"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-zinc-300">Inbox</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">Notifications</h1>
                <p class="mt-1.5 text-sm font-medium text-zinc-400">
                    @if($unreadCount > 0)
                        <span class="text-brand-400 font-bold">{{ $unreadCount }} unread</span> · All your alerts and updates in one place.
                    @else
                        You're all caught up.
                    @endif
                </p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                @if($unreadCount > 0)
                    <button wire:click="markAllRead"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/20 bg-white/10 px-4 py-2.5 text-sm font-bold text-white backdrop-blur-sm transition-all hover:bg-white/20">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                        Mark All Read
                    </button>
                @endif
                <button wire:click="clearRead" wire:confirm="Delete all read notifications?"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-4 py-2.5 text-sm font-bold text-zinc-400 transition-all hover:bg-white/10 hover:text-white">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m14.74 9-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 0 1-2.244 2.077H8.084a2.25 2.25 0 0 1-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 0 0-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 0 1 3.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 0 0-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 0 0-7.5 0" /></svg>
                    Clear Read
                </button>
            </div>
        </div>
    </div>

    {{-- ─── FILTERS ─── --}}
    <div class="flex flex-wrap items-center gap-3">
        {{-- Read/Unread tabs --}}
        <div class="flex items-center gap-1 rounded-xl border border-zinc-200 bg-white p-1 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            @foreach(['all' => 'All', 'unread' => 'Unread', 'read' => 'Read'] as $val => $label)
                <button wire:click="setFilter('{{ $val }}')"
                    class="rounded-lg px-4 py-1.5 text-sm font-bold transition-all
                        {{ $filter === $val
                            ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900'
                            : 'text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300' }}">
                    {{ $label }}
                    @if($val === 'unread' && $unreadCount > 0)
                        <span class="ml-1 rounded-full bg-brand-500 px-1.5 py-0.5 text-[9px] font-black text-white">{{ $unreadCount }}</span>
                    @endif
                </button>
            @endforeach
        </div>

        {{-- Type filter pills --}}
        @foreach($allTypes as $type)
            <button wire:click="setType('{{ $type }}')"
                class="rounded-full border px-3 py-1 text-xs font-bold transition-all
                    {{ $typeFilter === $type
                        ? 'border-zinc-900 bg-zinc-900 text-white dark:border-white dark:bg-white dark:text-zinc-900'
                        : 'border-zinc-200 bg-white text-zinc-500 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-400 dark:hover:border-zinc-600' }}">
                {{ notifTypeLabel($type) }}
            </button>
        @endforeach
    </div>

    {{-- ─── NOTIFICATIONS LIST ─── --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        @forelse($notifications as $notif)
            @php
                $data    = $notif->data;
                $isUnread = is_null($notif->read_at);
                $color   = $data['color'] ?? 'blue';
                [$iconBg, $iconText, $dotColor] = notifColorClasses($color);
                $icon    = $data['icon'] ?? 'bell';
                $title   = $data['title'] ?? '';
                $body    = $data['body'] ?? $data['message'] ?? '';
                $url     = $data['url'] ?? null;
                $action  = $data['action'] ?? 'View';
                $type    = $data['type'] ?? '';
            @endphp

            <div class="group relative flex items-start gap-4 border-b border-zinc-50 px-5 py-4 transition-colors last:border-b-0
                {{ $isUnread ? 'bg-blue-50/30 dark:bg-blue-950/10' : 'hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20' }}">

                {{-- Unread dot --}}
                @if($isUnread)
                    <div class="absolute left-0 top-1/2 -translate-y-1/2 w-1 h-8 rounded-r-full {{ $dotColor }}"></div>
                @endif

                {{-- Icon --}}
                <div class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-xl {{ $iconBg }}">
                    <svg class="size-5 {{ $iconText }}" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor">
                        @switch($icon)
                            @case('check-circle')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                @break
                            @case('x-circle')
                                <path stroke-linecap="round" stroke-linejoin="round" d="m9.75 9.75 4.5 4.5m0-4.5-4.5 4.5M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                @break
                            @case('banknotes')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0 1 15.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 0 1 3 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 0 0-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 0 1-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 0 0 3 15h-.75" />
                                @break
                            @case('receipt-refund')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M8.25 9.75h4.875a2.625 2.625 0 0 1 0 5.25H12M8.25 9.75 10.5 7.5M8.25 9.75 10.5 12m9-7.243V21.75l-3.75-1.5-3.75 1.5-3.75-1.5-3.75 1.5V4.757c0-1.108.806-2.057 1.907-2.185a48.507 48.507 0 0 1 11.186 0c1.1.128 1.907 1.077 1.907 2.185z" />
                                @break
                            @case('currency-rupee')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 8.25H9m6 3H9m3 6-3-3h1.5a3 3 0 1 0 0-6M3.375 7.5h17.25c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125H3.375c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z" />
                                @break
                            @case('clock')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0z" />
                                @break
                            @case('calendar')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5" />
                                @break
                            @case('document-text')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9z" />
                                @break
                            @case('clipboard-document-check')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75" />
                                @break
                            @case('exclamation-triangle')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                                @break
                            @case('user-check')
                                <path stroke-linecap="round" stroke-linejoin="round" d="M22 10.5h-6m-2.25-4.125a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0ZM4 19.235v-.11a6.375 6.375 0 0 1 12.75 0v.109A12.318 12.318 0 0 1 10.374 21c-2.331 0-4.512-.645-6.374-1.766Z" />
                                @break
                            @case('information-circle')
                                <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0zm-9-3.75h.008v.008H12V8.25z" />
                                @break
                            @default
                                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                        @endswitch
                    </svg>
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-bold text-zinc-900 dark:text-white {{ $isUnread ? '' : 'opacity-70' }}">
                                {{ $title }}
                            </p>
                            <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400 line-clamp-2">{{ $body }}</p>
                            <div class="mt-2 flex items-center gap-3">
                                <span class="text-[11px] text-zinc-400">{{ $notif->created_at->diffForHumans() }}</span>
                                @if($type)
                                    <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2 py-0.5 text-[10px] font-bold text-zinc-500 dark:text-zinc-400 uppercase tracking-wide">
                                        {{ notifTypeLabel($type) }}
                                    </span>
                                @endif
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="flex shrink-0 items-center gap-1.5 opacity-0 group-hover:opacity-100 transition-opacity">
                            @if($isUnread)
                                <button wire:click="markRead('{{ $notif->id }}')" title="Mark as read"
                                    class="flex size-7 cursor-pointer items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800">
                                    <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                </button>
                            @endif
                            <button wire:click="delete('{{ $notif->id }}')" wire:confirm="Delete this notification?" title="Delete"
                                class="flex size-7 cursor-pointer items-center justify-center rounded-lg text-zinc-400 transition-colors hover:bg-rose-50 hover:text-rose-500 dark:hover:bg-rose-950/30">
                                <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </div>
                </div>

                {{-- Action button + unread dot --}}
                <div class="flex shrink-0 flex-col items-end gap-2">
                    @if($url)
                        <button wire:click="redirectTo('{{ $notif->id }}')"
                            class="cursor-pointer rounded-lg border border-zinc-200 bg-white px-3 py-1 text-xs font-bold text-zinc-600 shadow-sm transition-all hover:border-zinc-300 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:border-zinc-600">
                            {{ $action }} →
                        </button>
                    @endif
                    @if($isUnread)
                        <div class="size-2 rounded-full bg-blue-500"></div>
                    @endif
                </div>
            </div>

        @empty
            <div class="flex flex-col items-center justify-center py-20 text-center">
                <div class="mb-4 flex size-16 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                    <svg class="size-8 text-zinc-300 dark:text-zinc-600" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0" />
                    </svg>
                </div>
                <p class="text-sm font-bold text-zinc-400">
                    @if($filter === 'unread') No unread notifications.
                    @elseif($filter === 'read') No read notifications.
                    @else You have no notifications yet.
                    @endif
                </p>
                <p class="mt-1 text-xs text-zinc-300 dark:text-zinc-600">Notifications will appear here when there's activity.</p>
            </div>
        @endforelse
    </div>

    {{-- Pagination --}}
    @if($notifications->hasPages())
        <div>{{ $notifications->links() }}</div>
    @endif

</flux:main>
