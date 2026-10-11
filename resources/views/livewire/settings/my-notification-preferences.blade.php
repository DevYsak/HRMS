<x-pages::settings.layout :heading="__('My notifications')" :subheading="__('Choose which optional notifications you receive by email or in the app. Notifications marked Required are set by HR and always reach you.')">

    {{--
        One fixed column grid for every group (label | Email | In-app), so the
        toggles line up across groups on desktop. Below sm each event stacks:
        its name on one line, the two labelled toggles underneath.
    --}}
    <div class="space-y-5" data-notification-preferences>
        @forelse($settings as $group => $rows)
            <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900" data-notification-group="{{ $group ?: 'General' }}">
                <header class="grid grid-cols-1 items-center gap-2 border-b border-zinc-100 bg-zinc-50/70 px-4 py-2.5 sm:grid-cols-[minmax(0,1fr)_6rem_6rem] dark:border-white/5 dark:bg-white/5">
                    <h3 class="min-w-0 text-[11px] font-bold uppercase tracking-wider text-zinc-500">{{ $group ?: 'General' }}</h3>
                    <span class="hidden text-center text-[11px] font-bold uppercase tracking-wider text-zinc-400 sm:block">{{ __('Email') }}</span>
                    <span class="hidden text-center text-[11px] font-bold uppercase tracking-wider text-zinc-400 sm:block">{{ __('In-app') }}</span>
                </header>

                <ul class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($rows as $s)
                        @php $pref = $preferences->get($s->key); @endphp
                        <li wire:key="np-{{ $s->id }}" class="grid grid-cols-1 gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_6rem_6rem] sm:items-center" data-notification-row>
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-zinc-800 [overflow-wrap:anywhere] dark:text-zinc-100">{{ $s->label }}</p>
                                @if($s->is_mandatory)
                                    <span class="mt-1 inline-flex rounded bg-rose-50 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">{{ __('Required') }}</span>
                                @endif
                            </div>

                            <div class="grid grid-cols-2 gap-3 sm:contents">
                                @foreach(['mail' => $s->mail_enabled, 'database' => $s->database_enabled] as $channel => $enabled)
                                    @php
                                        $on = $enabled && ($s->is_mandatory || ! ($channel === 'mail' ? $pref?->mail_muted : $pref?->database_muted));
                                        $channelLabel = $channel === 'mail' ? __('Email') : __('In-app');
                                    @endphp
                                    <div class="flex items-center justify-between gap-2 rounded-xl bg-zinc-50 px-2.5 py-2 sm:justify-center sm:bg-transparent sm:p-0 dark:bg-white/5 sm:dark:bg-transparent">
                                        <span class="whitespace-nowrap text-xs font-medium text-zinc-500 sm:sr-only">{{ $channelLabel }}</span>
                                        @if(! $enabled)
                                            <span class="text-[11px] text-zinc-400" title="{{ __('Switched off by HR') }}">{{ __('Off') }}</span>
                                        @else
                                            <button type="button" role="switch" aria-checked="{{ $on ? 'true' : 'false' }}"
                                                wire:click="toggle({{ $s->id }}, '{{ $channel }}')" @disabled($s->is_mandatory)
                                                @if($s->is_mandatory) title="{{ __('Required by HR') }}" @endif
                                                @class(['relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-orange-500/40 disabled:cursor-not-allowed disabled:opacity-60', 'bg-orange-500' => $on, 'bg-zinc-200 dark:bg-zinc-700' => ! $on])
                                                aria-label="{{ $s->label }} — {{ $channelLabel }}">
                                                <span @class(['inline-block size-4 rounded-full bg-white shadow transition', 'translate-x-4' => $on, 'translate-x-0.5' => ! $on])></span>
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>
        @empty
            <p class="text-sm text-zinc-400">{{ __('No notifications are configured yet.') }}</p>
        @endforelse
    </div>

</x-pages::settings.layout>
