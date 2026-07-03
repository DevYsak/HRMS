<flux:main class="space-y-6 p-4 md:p-6">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Mail Center</flux:heading>
            <flux:subheading>Master switch for all outgoing email, plus one-off broadcasts to your team.</flux:subheading>
        </div>
    </div>

    {{-- Master kill switch --}}
    <div @class([
        'rounded-2xl border p-5 shadow-sm transition',
        'border-emerald-200 bg-emerald-50/60 dark:border-emerald-500/20 dark:bg-emerald-900/10' => $mailEnabled,
        'border-rose-200 bg-rose-50/60 dark:border-rose-500/20 dark:bg-rose-900/10' => ! $mailEnabled,
    ])>
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div class="flex items-start gap-3">
                <flux:icon.envelope @class([
                    'mt-0.5 size-6',
                    'text-emerald-500' => $mailEnabled,
                    'text-rose-500' => ! $mailEnabled,
                ]) />
                <div>
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">
                        Outgoing email is {{ $mailEnabled ? 'ENABLED' : 'PAUSED' }}
                    </h3>
                    <p class="mt-0.5 max-w-lg text-xs text-zinc-500 dark:text-zinc-400">
                        The master switch controls <b>every</b> email the system sends — leave &amp; payroll
                        notifications, welcome emails, and broadcasts below. Turn it off to pause all mail instantly.
                    </p>
                </div>
            </div>

            {{-- One-shot toggle button --}}
            <button type="button" wire:click="toggleMaster"
                @class([
                    'relative inline-flex h-7 w-12 shrink-0 items-center rounded-full transition',
                    'bg-emerald-500' => $mailEnabled,
                    'bg-zinc-300 dark:bg-zinc-600' => ! $mailEnabled,
                ])
                aria-label="Toggle all outgoing email">
                <span @class([
                    'inline-block size-5 transform rounded-full bg-white shadow transition',
                    'translate-x-6' => $mailEnabled,
                    'translate-x-1' => ! $mailEnabled,
                ])></span>
            </button>
        </div>

        @if($mailer === 'log')
            <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-medium text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                Mailer is set to <b>log</b> — emails are written to the log, not delivered. Set
                <code>MAIL_MAILER=smtp</code> to actually send.
            </p>
        @endif
    </div>

    {{-- Composer + Recipients --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-5">

        {{-- Compose --}}
        <div class="space-y-4 lg:col-span-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="mb-4 flex items-center gap-2">
                    <flux:icon.pencil-square class="size-5 text-orange-500" />
                    <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Compose Broadcast</h3>
                </div>

                {{-- Draft with AI --}}
                @if($aiEnabled)
                    <div class="mb-4 rounded-xl border border-orange-200 bg-orange-50/60 p-3 dark:border-orange-500/20 dark:bg-orange-900/10">
                        <flux:field>
                            <flux:label class="!text-xs !font-bold !uppercase !tracking-wide !text-orange-600 dark:!text-orange-400">
                                Draft with AI
                            </flux:label>
                            <div class="flex items-start gap-2">
                                <flux:input wire:model="aiPrompt" size="sm"
                                    placeholder="e.g. remind everyone the office is closed Friday for Diwali" />
                                <flux:button wire:click="draftWithAi" variant="primary" size="sm" icon="sparkles"
                                    class="shrink-0" wire:loading.attr="disabled" wire:target="draftWithAi">
                                    <span wire:loading.remove wire:target="draftWithAi">Draft</span>
                                    <span wire:loading wire:target="draftWithAi">Writing…</span>
                                </flux:button>
                            </div>
                            <flux:error name="aiPrompt" />
                        </flux:field>
                        <p class="mt-1.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                            AI fills in the subject &amp; body below — review and edit before sending.
                        </p>
                    </div>
                @endif

                <div class="space-y-4">
                    <flux:input wire:model="subject" label="Subject" placeholder="Email subject" />
                    <flux:textarea wire:model="body" label="Message" rows="10"
                        placeholder="Write your message to the team…" />
                </div>

                <div class="mt-4 flex items-center justify-between gap-3">
                    <span class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ count($selected) }} recipient(s) selected
                    </span>
                    <flux:button wire:click="send" variant="primary" icon="paper-airplane"
                        :disabled="! $mailEnabled || count($selected) === 0"
                        wire:loading.attr="disabled" wire:target="send">
                        <span wire:loading.remove wire:target="send">Send broadcast</span>
                        <span wire:loading wire:target="send">Sending…</span>
                    </flux:button>
                </div>
                @error('subject') <p class="mt-2 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('body') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                @error('selected') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
        </div>

        {{-- Recipients --}}
        <div class="lg:col-span-2">
            <div class="flex h-full flex-col rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 px-5 py-3 dark:border-white/5">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Recipients</h3>
                        <flux:checkbox wire:model.live="selectAll" label="Select all" />
                    </div>
                    <div class="mt-3">
                        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass"
                            placeholder="Search employees…" size="sm" />
                    </div>
                </div>

                <div class="max-h-[26rem] flex-1 divide-y divide-zinc-100 overflow-y-auto dark:divide-white/5">
                    @forelse($recipients as $u)
                        <label class="flex cursor-pointer items-center gap-3 px-5 py-2.5 transition hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                            <flux:checkbox wire:model.live="selected" value="{{ $u->id }}" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $u->name }}</div>
                                <div class="truncate text-[11px] text-zinc-400">
                                    {{ $u->email }}
                                    @if($u->employee?->department)
                                        · {{ $u->employee->department->name }}
                                    @endif
                                </div>
                            </div>
                        </label>
                    @empty
                        <p class="px-5 py-8 text-center text-sm text-zinc-400">No employees with an email found.</p>
                    @endforelse
                </div>
            </div>
        </div>
    </div>

    {{-- Recent broadcasts --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
            <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Recent Broadcasts</h3>
        </div>
        @if($logs->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-zinc-400">No broadcasts sent yet.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                        <th class="px-5 py-2 text-left">To</th>
                        <th class="px-3 py-2 text-left">Subject</th>
                        <th class="px-3 py-2 text-center">Status</th>
                        <th class="px-5 py-2 text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($logs as $log)
                        <tr>
                            <td class="px-5 py-2.5 text-zinc-700 dark:text-zinc-300">{{ $log->to_email }}</td>
                            <td class="px-3 py-2.5 text-zinc-500">{{ \Illuminate\Support\Str::limit($log->subject, 48) }}</td>
                            <td class="px-3 py-2.5 text-center">
                                <span @class([
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold',
                                    'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $log->status === 'sent',
                                    'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' => $log->status === 'sending',
                                    'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' => $log->status === 'failed',
                                ])>{{ ucfirst($log->status) }}</span>
                            </td>
                            <td class="px-5 py-2.5 text-right text-[11px] text-zinc-400">{{ $log->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</flux:main>
