<flux:main class="space-y-6 p-4 md:p-6">

    {{-- Header --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Notifications &amp; Email</flux:heading>
            <flux:subheading>Control every transactional email and in-app notification.</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:button wire:click="openCompose" icon="paper-airplane" variant="primary" size="sm">
                Compose &amp; Send
            </flux:button>
            <flux:button wire:click="syncCatalog" icon="arrow-path" variant="ghost" size="sm">
                Re-scan types
            </flux:button>
        </div>
    </div>

    {{-- Master mail switch --}}
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
                    <p class="mt-0.5 max-w-xl text-xs text-zinc-500 dark:text-zinc-400">
                        This master switch controls <b>every</b> email the system sends — the notifications
                        below, and any broadcast you compose. Turn it off to pause all mail instantly.
                    </p>
                </div>
            </div>

            {{-- One-shot enable/disable button --}}
            <button type="button" wire:click="toggleMasterMail"
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
    </div>

    {{-- Status cards: SMTP + Queue + Test --}}
    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
        {{-- SMTP --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.envelope class="size-5 text-orange-500" />
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">SMTP Status</h3>
            </div>
            <dl class="space-y-1.5 text-xs">
                <div class="flex justify-between"><dt class="text-zinc-500">Mailer</dt><dd class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $smtp['mailer'] }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Host</dt><dd class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $smtp['host'] ?? '—' }}:{{ $smtp['port'] ?? '' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">From</dt><dd class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $smtp['from'] ?? '—' }}</dd></div>
                <div class="flex justify-between"><dt class="text-zinc-500">Queue</dt><dd class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $smtp['queue'] }}</dd></div>
            </dl>
            @if($smtp['mailer'] === 'log')
                <p class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-[11px] font-medium text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">
                    Mailer is set to <b>log</b> — emails are written to the log, not delivered. Set <code>MAIL_MAILER=smtp</code> to send.
                </p>
            @endif
        </div>

        {{-- Queue --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.queue-list class="size-5 text-orange-500" />
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Queue Status</h3>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-xl bg-zinc-50 p-3 text-center dark:bg-zinc-800/50">
                    <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ $queue['pending'] }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Pending</div>
                </div>
                <div class="rounded-xl bg-rose-50 p-3 text-center dark:bg-rose-900/20">
                    <div class="text-2xl font-black text-rose-600 dark:text-rose-400">{{ $queue['failed'] }}</div>
                    <div class="text-[10px] font-bold uppercase tracking-wide text-rose-400">Failed</div>
                </div>
            </div>
            <div class="mt-3 flex gap-2">
                <flux:button wire:click="retryFailed" size="xs" variant="primary" class="flex-1" :disabled="$queue['failed'] === 0">Retry failed</flux:button>
                <flux:button wire:click="clearFailed" size="xs" variant="ghost" class="flex-1" :disabled="$queue['failed'] === 0">Clear</flux:button>
            </div>
        </div>

        {{-- Test email --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-3 flex items-center gap-2">
                <flux:icon.paper-airplane class="size-5 text-orange-500" />
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white">Send Test Email</h3>
            </div>
            <flux:input wire:model="testEmail" type="email" placeholder="you@example.com" size="sm" />
            <flux:button wire:click="sendTest" variant="primary" size="sm" class="mt-3 w-full" icon="paper-airplane">
                Send test
            </flux:button>
            <p class="mt-2 text-[11px] text-zinc-400">Verifies SMTP and writes to the email log below.</p>
        </div>
    </div>

    {{-- Search --}}
    <div class="max-w-sm">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search notifications…" size="sm" />
    </div>

    <p class="text-xs text-zinc-400">
        <b>Email</b> / <b>In-App</b> control whether that channel fires at all for this event.
        <b>Auto-send</b> only affects email, and only automatic sends the system triggers on its own —
        turning it off does not stop the event from happening or from appearing in-app, and does not
        block a deliberate action like Send Test. An event that notifies more than one role (↳ rows
        below its name) can have each role's channels and template configured independently.
    </p>

    {{-- Notification settings grouped --}}
    <div class="space-y-6">
        @forelse($settings as $group => $rows)
            <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
                    <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">{{ $group }}</h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                            <th class="px-5 py-2 text-left">Notification</th>
                            <th class="px-3 py-2 text-center">Email</th>
                            <th class="px-3 py-2 text-center">In-App</th>
                            <th class="px-3 py-2 text-center">Auto-send</th>
                            <th class="px-5 py-2 text-right">Template</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $s)
                            @php($roles = $catalog->rolesFor($s->key))
                            <tr class="transition hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                                <td class="px-5 py-3">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $s->label }}</div>
                                    @if($roles !== [])
                                        <div class="mt-0.5 text-[11px] text-zinc-400">Default — applies to any role below with no override</div>
                                    @elseif($s->custom_subject)
                                        <div class="mt-0.5 truncate text-[11px] text-zinc-400">Subject: {{ $s->custom_subject }}</div>
                                    @endif
                                </td>
                                @foreach(['mail_enabled', 'database_enabled', 'is_automatic'] as $field)
                                    <td class="px-3 py-3 text-center">
                                        <button type="button" wire:click="toggle({{ $s->id }}, '{{ $field }}')"
                                            @class([
                                                'relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition',
                                                'bg-orange-500' => $s->{$field},
                                                'bg-zinc-200 dark:bg-zinc-700' => ! $s->{$field},
                                            ])
                                            aria-label="Toggle {{ $field }}">
                                            <span @class([
                                                'inline-block size-4 transform rounded-full bg-white shadow transition',
                                                'translate-x-4' => $s->{$field},
                                                'translate-x-0.5' => ! $s->{$field},
                                            ])></span>
                                        </button>
                                    </td>
                                @endforeach
                                <td class="px-5 py-3 text-right">
                                    <flux:button wire:click="openEdit({{ $s->id }})" size="xs" variant="ghost" icon="pencil-square">Edit</flux:button>
                                </td>
                            </tr>
                            {{-- Per-role overrides: only for events that notify more than one role --}}
                            @foreach($s->roleSettings as $rs)
                                <tr class="bg-zinc-50/40 dark:bg-zinc-800/20">
                                    <td class="py-2 pl-10 pr-5 text-xs text-zinc-500 dark:text-zinc-400">
                                        ↳ {{ $roles[$rs->role] ?? \Illuminate\Support\Str::headline($rs->role) }}
                                        @if($rs->custom_subject)
                                            <div class="mt-0.5 truncate text-[11px] text-zinc-400">Subject: {{ $rs->custom_subject }}</div>
                                        @endif
                                    </td>
                                    @foreach(['mail_enabled', 'database_enabled', 'is_automatic'] as $field)
                                        <td class="px-3 py-2 text-center">
                                            <button type="button" wire:click="toggleRole({{ $rs->id }}, '{{ $field }}')"
                                                @class([
                                                    'relative inline-flex h-4 w-8 shrink-0 items-center rounded-full transition',
                                                    'bg-orange-400' => $rs->{$field},
                                                    'bg-zinc-200 dark:bg-zinc-700' => ! $rs->{$field},
                                                ])
                                                aria-label="Toggle {{ $roles[$rs->role] ?? $rs->role }} {{ $field }}">
                                                <span @class([
                                                    'inline-block size-3 transform rounded-full bg-white shadow transition',
                                                    'translate-x-4' => $rs->{$field},
                                                    'translate-x-0.5' => ! $rs->{$field},
                                                ])></span>
                                            </button>
                                        </td>
                                    @endforeach
                                    <td class="px-5 py-2 text-right">
                                        <flux:button wire:click="openEditRole({{ $rs->id }})" size="xs" variant="ghost" icon="pencil-square">Edit</flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center text-sm text-zinc-400 dark:border-zinc-700">
                No notification types found. Click <b>Re-scan types</b> to discover them.
            </div>
        @endforelse
    </div>

    {{-- Recent email logs --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
            <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Recent Emails</h3>
        </div>
        @if($logs->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-zinc-400">No emails sent yet.</p>
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
                                    'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' => $log->status === 'skipped',
                                ])
                                    title="{{ $log->skip_reason }}"
                                >{{ ucfirst($log->status) }}{{ $log->skip_reason ? ' — '.\Illuminate\Support\Str::headline($log->skip_reason) : '' }}</span>
                            </td>
                            <td class="px-5 py-2.5 text-right text-[11px] text-zinc-400">{{ $log->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Compose & send broadcast modal --}}
    <flux:modal wire:model.self="showComposeModal" class="w-full max-w-2xl">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Compose &amp; Send</flux:heading>
                <flux:subheading>Send a one-off email to selected employees.</flux:subheading>
            </div>

            @if(! $mailEnabled)
                <p class="rounded-lg bg-rose-50 px-3 py-2 text-xs font-medium text-rose-700 dark:bg-rose-900/20 dark:text-rose-300">
                    Outgoing email is paused by the master switch above. Enable it to send.
                </p>
            @endif

            {{-- Draft with AI (only when AI is configured) --}}
            @if($aiEnabled)
                <div class="rounded-xl border border-orange-200 bg-orange-50/60 p-3 dark:border-orange-500/20 dark:bg-orange-900/10">
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
                </div>
            @endif

            <flux:input wire:model="composeSubject" label="Subject" placeholder="Email subject" />
            <flux:error name="composeSubject" />
            <flux:textarea wire:model="composeBody" label="Message" rows="7"
                placeholder="Write your message to the team…" />
            <flux:error name="composeBody" />

            {{-- Recipients --}}
            <div class="rounded-xl border border-zinc-200 dark:border-white/10">
                <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-white/5">
                    <span class="text-xs font-bold uppercase tracking-wide text-zinc-500">
                        Recipients ({{ count($selectedRecipients) }})
                    </span>
                    <flux:checkbox wire:model.live="selectAllRecipients" label="Select all" />
                </div>
                <div class="p-3">
                    <flux:input wire:model.live.debounce.300ms="recipientSearch" icon="magnifying-glass"
                        placeholder="Search employees…" size="sm" />
                </div>
                <div class="max-h-56 divide-y divide-zinc-100 overflow-y-auto dark:divide-white/5">
                    @forelse($recipientList as $u)
                        <label class="flex cursor-pointer items-center gap-3 px-4 py-2 transition hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                            <flux:checkbox wire:model.live="selectedRecipients" value="{{ $u->id }}" />
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
                        <p class="px-4 py-6 text-center text-sm text-zinc-400">No employees with an email found.</p>
                    @endforelse
                </div>
                <div class="px-4 pb-1">
                    <flux:error name="selectedRecipients" />
                </div>
            </div>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showComposeModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="sendBroadcast" variant="primary" icon="paper-airplane"
                    :disabled="! $mailEnabled" wire:loading.attr="disabled" wire:target="sendBroadcast">
                    <span wire:loading.remove wire:target="sendBroadcast">Send broadcast</span>
                    <span wire:loading wire:target="sendBroadcast">Sending…</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit template modal --}}
    <flux:modal wire:model.self="showEditModal" class="w-full max-w-lg">
        <div class="space-y-4">
            <div>
                <flux:heading size="lg">Email Template</flux:heading>
                <flux:subheading>Override the subject and body for this notification. Leave blank to use the default.</flux:subheading>
            </div>
            <flux:input wire:model="custom_subject" label="Custom Subject" placeholder="Default subject" />
            <flux:textarea wire:model="custom_body" label="Custom Body" rows="5" placeholder="Default message body" />
            <flux:error name="custom_body" />

            @if($editVariables !== [])
                <div class="rounded-xl border border-zinc-200 bg-zinc-50/60 p-3 text-xs dark:border-white/10 dark:bg-zinc-800/30">
                    <div class="mb-1.5 font-bold uppercase tracking-wide text-zinc-500">Available variables</div>
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($editVariables as $name => $description)
                            @php($token = '{{'.$name.'}}')
                            <span class="rounded-md bg-white px-1.5 py-0.5 font-mono text-[11px] text-orange-600 shadow-sm dark:bg-zinc-900 dark:text-orange-400"
                                title="{{ $description }}">{{ $token }}</span>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="flex items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-white/5">
                <div class="flex gap-2">
                    <flux:button wire:click="previewEdit" size="sm" variant="ghost" icon="eye">Preview</flux:button>
                    <flux:button wire:click="sendTestFromEdit" size="sm" variant="ghost" icon="paper-airplane">Send test</flux:button>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showEditModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="saveEdit" variant="primary">Save</flux:button>
                </div>
            </div>
        </div>
    </flux:modal>

    {{-- Preview modal --}}
    <flux:modal wire:model.self="showPreviewModal" class="w-full max-w-lg">
        <div class="space-y-3">
            <flux:heading size="lg">Preview</flux:heading>
            <flux:subheading>Rendered with sample data — never a real employee's.</flux:subheading>
            <div class="rounded-xl border border-zinc-200 p-4 dark:border-white/10">
                <div class="mb-2 border-b border-zinc-100 pb-2 text-sm font-bold text-zinc-900 dark:border-white/5 dark:text-white">
                    {{ $previewSubject }}
                </div>
                <div class="whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ $previewBody }}</div>
            </div>
            <div class="flex justify-end">
                <flux:button wire:click="$set('showPreviewModal', false)" variant="ghost">Close</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
