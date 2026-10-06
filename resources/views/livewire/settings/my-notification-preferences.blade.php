<flux:main class="space-y-6 p-4 md:p-6">

    <div>
        <flux:heading size="xl">My notifications</flux:heading>
        <flux:subheading>Choose which optional notifications you receive by email or in the app. Notifications marked Required are set by HR and always reach you.</flux:subheading>
    </div>

    <div class="space-y-4">
        @forelse($settings as $group => $rows)
            <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <table class="w-full min-w-[480px] text-sm">
                    <thead class="bg-zinc-50/70 text-[10px] font-bold uppercase tracking-wider text-zinc-400 dark:bg-white/5">
                        <tr>
                            <th class="px-4 py-2 text-left">{{ $group ?: 'General' }}</th>
                            <th class="px-3 py-2 text-center">Email</th>
                            <th class="px-3 py-2 text-center">In-app</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $s)
                            @php $pref = $preferences->get($s->key); @endphp
                            <tr wire:key="np-{{ $s->id }}">
                                <td class="px-4 py-2.5">
                                    <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $s->label }}</span>
                                    @if($s->is_mandatory)<span class="ml-1 rounded bg-rose-50 px-1.5 text-[9px] font-bold uppercase text-rose-600 dark:bg-rose-500/10 dark:text-rose-300">Required</span>@endif
                                </td>
                                @foreach(['mail' => $s->mail_enabled, 'database' => $s->database_enabled] as $channel => $enabled)
                                    @php $on = $enabled && ($s->is_mandatory || ! ($channel === 'mail' ? $pref?->mail_muted : $pref?->database_muted)); @endphp
                                    <td class="px-3 py-2.5 text-center">
                                        @if(! $enabled)
                                            <span class="text-[11px] text-zinc-400" title="Switched off by HR">Off</span>
                                        @else
                                            <button type="button" wire:click="toggle({{ $s->id }}, '{{ $channel }}')" @disabled($s->is_mandatory)
                                                @class(['relative inline-flex h-5 w-9 items-center rounded-full transition disabled:cursor-not-allowed disabled:opacity-60', 'bg-orange-500' => $on, 'bg-zinc-200 dark:bg-zinc-700' => ! $on])
                                                aria-label="{{ $s->label }} {{ $channel === 'mail' ? 'email' : 'in-app' }}">
                                                <span @class(['inline-block size-4 rounded-full bg-white shadow transition', 'translate-x-4' => $on, 'translate-x-0.5' => ! $on])></span>
                                            </button>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-sm text-zinc-400">No notifications are configured yet.</p>
        @endforelse
    </div>

</flux:main>
