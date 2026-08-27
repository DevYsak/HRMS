{{-- Employee record editor.

     Two-level navigation. Sixteen panels on one rail is unreadable at any
     spacing, so they sit under six categories: the primary row picks the area,
     the secondary row picks the panel within it. The active category is derived
     from the active tab rather than stored, so no component state changed and
     every existing wire binding still works.

     Panels below the chrome keep their own markup. --}}
<flux:main class="nx-record min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-['Inter'] md:p-6 dark:bg-[#0B1220]">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap');

        /* Scoped to this page. Flux inputs default to a compact height that
           reads as cramped beside cards this size, and their focus ring is the
           framework blue rather than the Nexcore accent. */
        .nx-record input:not([type=checkbox]):not([type=radio]),
        .nx-record select,
        .nx-record [data-flux-input] input,
        .nx-record [data-flux-select] button {
            min-height: 3rem;
            border-radius: 0.75rem;
        }
        .nx-record input:focus-visible,
        .nx-record select:focus-visible,
        .nx-record [data-flux-input] input:focus {
            outline: none;
            border-color: #fb923c !important;
            box-shadow: 0 0 0 3px rgba(249, 115, 22, 0.14) !important;
        }
        .nx-record [data-flux-field] label {
            font-weight: 600;
            color: #374151;
        }
        .dark .nx-record [data-flux-field] label { color: #d4d4d8; }

        /* 150–250ms, applied to the few things that actually move. */
        .nx-tab, .nx-chip, .nx-shortcut { transition: all 180ms cubic-bezier(.4, 0, .2, 1); }
        .nx-chip:hover { transform: translateY(-1px); }
        .nx-scroll { scrollbar-width: none; }
        .nx-scroll::-webkit-scrollbar { display: none; }
    </style>

    @php
        // Status chip. Onboarding reads as informational rather than a warning,
        // which is why it is blue and not amber.
        $statusTone = match($employee->status?->value) {
            'active', 'confirmed' => 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
            'probation'           => 'bg-amber-50 text-amber-700 ring-amber-600/20',
            'onboarding', 'draft' => 'bg-blue-50 text-blue-700 ring-blue-600/20',
            'notice_period', 'resigned', 'terminated', 'absconded' => 'bg-rose-50 text-rose-700 ring-rose-600/20',
            default               => 'bg-zinc-100 text-zinc-600 ring-zinc-500/20',
        };

        // Six areas rather than sixteen siblings. Derived, not stored: clicking
        // an area opens its first panel, and the area highlights because the
        // active panel belongs to it.
        $areas = [
            'Record'  => ['icon' => 'identification',      'tabs' => ['General', 'Personal', 'Job', 'Documents']],
            'Time'    => ['icon' => 'clock',               'tabs' => ['Attendance', 'Leave', 'OT']],
            'Growth'  => ['icon' => 'arrow-trending-up',   'tabs' => ['Performance', 'Promotions', 'Probation', 'PIP']],
            'Conduct' => ['icon' => 'exclamation-triangle','tabs' => ['Warnings']],
            'Pay'     => ['icon' => 'banknotes',           'tabs' => ['Payroll']],
            'History' => ['icon' => 'clock',               'tabs' => ['Timeline', 'Activity']],
        ];

        if (in_array($employee->ot_tracking_source, ['nexflow', 'hybrid'], true)) {
            $areas['Time']['tabs'][] = 'Nexflow';
        }

        $activeArea = collect($areas)->search(fn ($a) => in_array($activeTab, $a['tabs'], true)) ?: 'Record';

        $quickFacts = [
            ['envelope',        'Emp ID',  $employee->employee_id ?: 'Not set'],
            ['calendar-days',   'Joined',  $employee->joining_date?->format('d M Y') ?? 'Not set'],
            ['building-office', 'Office',  $employee->office?->name ?? 'Not set'],
            ['user-circle',     'Manager', $employee->manager?->name ?? 'Not set'],
        ];
    @endphp

    <flux:breadcrumbs>
        <flux:breadcrumbs.item :href="route('employees.index')" wire:navigate>Employees</flux:breadcrumbs.item>
        <flux:breadcrumbs.item>{{ $employee->user?->name ?? 'Employee' }}</flux:breadcrumbs.item>
    </flux:breadcrumbs>

    {{-- ── Hero ──────────────────────────────────────────────────────────── --}}
    <div class="relative overflow-hidden rounded-2xl border border-[#F2E3D5] bg-gradient-to-br from-[#FFF4EA] via-[#FFF9F4] to-[#FFF1E4] p-5 shadow-sm md:p-6 dark:border-white/10 dark:from-[#161E2E] dark:via-[#111827] dark:to-[#1B2436]">
        {{-- Two soft blooms rather than a busy pattern: enough to stop the card
             reading flat, quiet enough to sit behind text. --}}
        <div class="pointer-events-none absolute -right-16 -top-24 size-72 rounded-full bg-orange-400/20 blur-3xl"></div>
        <div class="pointer-events-none absolute -bottom-28 right-40 size-56 rounded-full bg-amber-300/15 blur-3xl"></div>

        <div class="relative flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex min-w-0 items-center gap-4">
                @if($employee->photo)
                    <img src="{{ asset('storage/'.$employee->photo) }}"
                        class="size-16 shrink-0 rounded-2xl object-cover shadow-sm ring-2 ring-white dark:ring-white/10" />
                @else
                    <div class="flex size-16 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-400 text-2xl font-extrabold text-white shadow-lg shadow-orange-500/30">
                        {{ strtoupper(substr($employee->user?->name ?? '?', 0, 1)) }}
                    </div>
                @endif

                <div class="min-w-0">
                    <h1 class="truncate text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">
                        {{ $employee->user?->name ?? 'Employee' }}
                    </h1>
                    <div class="mt-1.5 flex flex-wrap items-center gap-2 text-sm text-[#667085] dark:text-zinc-400">
                        <span class="font-medium">{{ $employee->jobTitle?->name ?? 'No job title' }}</span>
                        <span class="text-[#D7C3B0] dark:text-white/20">•</span>
                        <span class="font-medium">{{ $employee->department?->name ?? 'No department' }}</span>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-semibold ring-1 ring-inset {{ $statusTone }}">
                            {{ $employee->status?->label() ?? '—' }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- Hierarchy: one primary, one secondary, everything that changes
                 somebody's credentials behind the overflow where it has room to
                 be labelled. --}}
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <flux:button :href="route('employees.profile', $employee)" wire:navigate variant="primary" icon="user" size="sm">
                    View profile
                </flux:button>

                <flux:tooltip content="Email this employee">
                    <flux:button wire:click="openEmailModal" variant="filled" icon="envelope" size="sm">Send email</flux:button>
                </flux:tooltip>

                <div x-data="{ open: false }" class="relative" @click.outside="open = false">
                    <flux:tooltip content="More actions">
                        <flux:button @click="open = !open" variant="filled" size="sm" icon="ellipsis-horizontal" />
                    </flux:tooltip>

                    <div x-show="open" x-cloak
                        x-transition:enter="transition ease-out duration-150"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="absolute right-0 top-full z-50 mt-2 w-72 overflow-hidden rounded-2xl border border-[#EAECF0] bg-white shadow-xl dark:border-white/10 dark:bg-zinc-900">

                        <div class="px-4 pb-1 pt-3 text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">Account access</div>

                        <button type="button" wire:click="resetPassword" wire:loading.attr="disabled" wire:target="resetPassword" @click="open = false"
                            class="flex w-full items-start gap-3 px-4 py-2.5 text-left transition hover:bg-orange-50/70 dark:hover:bg-white/5">
                            <flux:icon.lock-closed class="mt-0.5 size-4 shrink-0 text-orange-500" />
                            <span>
                                <span class="block text-sm font-semibold text-[#101828] dark:text-zinc-100">
                                    <span wire:loading.remove wire:target="resetPassword">Send reset link</span>
                                    <span wire:loading wire:target="resetPassword">Sending…</span>
                                </span>
                                <span class="block text-xs text-[#667085] dark:text-zinc-400">They choose their own new password</span>
                            </span>
                        </button>

                        <button type="button" wire:click="setTemporaryPassword" wire:loading.attr="disabled" wire:target="setTemporaryPassword"
                            wire:confirm="This will reset their password to a new temporary one and email it to them. Continue?" @click="open = false"
                            class="flex w-full items-start gap-3 px-4 py-2.5 text-left transition hover:bg-orange-50/70 dark:hover:bg-white/5">
                            <flux:icon.key class="mt-0.5 size-4 shrink-0 text-orange-500" />
                            <span>
                                <span class="block text-sm font-semibold text-[#101828] dark:text-zinc-100">
                                    <span wire:loading.remove wire:target="setTemporaryPassword">Set temporary password</span>
                                    <span wire:loading wire:target="setTemporaryPassword">Resetting…</span>
                                </span>
                                <span class="block text-xs text-[#667085] dark:text-zinc-400">Replaces their password immediately</span>
                            </span>
                        </button>

                        <div class="my-1 border-t border-[#EAECF0] dark:border-white/10"></div>
                        <div class="px-4 pb-1 pt-2 text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">Record</div>

                        <button type="button" wire:click="setTab('Activity')" @click="open = false"
                            class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm font-semibold text-[#101828] transition hover:bg-orange-50/70 dark:text-zinc-100 dark:hover:bg-white/5">
                            <flux:icon.clock class="size-4 shrink-0 text-orange-500" />
                            View activity
                        </button>

                        @if($employee->status->value !== 'inactive')
                            <button type="button" wire:click="deactivate"
                                wire:confirm="Deactivate this employee? Their status will be set to Inactive." @click="open = false"
                                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm font-semibold text-rose-600 transition hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-500/10">
                                <flux:icon.no-symbol class="size-4 shrink-0" />
                                Deactivate employee
                            </button>
                        @else
                            <button type="button" wire:click="reactivate"
                                wire:confirm="Reactivate this employee? Their status will be set to Active." @click="open = false"
                                class="flex w-full items-center gap-3 px-4 py-2.5 text-left text-sm font-semibold text-emerald-600 transition hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-500/10">
                                <flux:icon.check-badge class="size-4 shrink-0" />
                                Reactivate employee
                            </button>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        {{-- Quick facts as chips. These are the four questions asked about
             somebody before opening any panel, so they answer before the tabs. --}}
        <div class="relative mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
            @foreach($quickFacts as [$icon, $label, $value])
                <div class="nx-chip flex items-center gap-3 rounded-xl border border-white/80 bg-white/80 px-3.5 py-3 shadow-sm backdrop-blur dark:border-white/10 dark:bg-white/5">
                    <div class="flex size-9 shrink-0 items-center justify-center rounded-lg bg-orange-50 text-orange-500 dark:bg-orange-500/10">
                        <flux:icon :name="$icon" class="size-4" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">{{ $label }}</div>
                        <div class="truncate text-sm font-semibold text-[#101828] dark:text-zinc-100">{{ $value }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ── Notices ───────────────────────────────────────────────────────── --}}
    @if(session('status'))
        <div x-data="{ show: true }" x-show="show" x-transition
            class="flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800 dark:border-emerald-900/40 dark:bg-emerald-950/20 dark:text-emerald-300">
            <flux:icon.check-circle class="mt-0.5 size-4 shrink-0" />
            <span class="flex-1">{{ session('status') }}</span>
            <button type="button" @click="show = false" class="text-emerald-600/60 transition hover:text-emerald-700">
                <flux:icon.x-mark class="size-4" />
            </button>
        </div>
    @endif

    @if($localResetUrl)
        <div class="space-y-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-4 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-950/20 dark:text-amber-200">
            <div class="flex items-start gap-3">
                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0" />
                <div>
                    <p class="font-semibold">Mail is configured for local logging, not delivery.</p>
                    <p class="mt-1 text-amber-800/90 dark:text-amber-200/90">Use this reset link for now, or point <code>MAIL_MAILER</code> at a real SMTP service to deliver it properly.</p>
                </div>
            </div>

            <div class="rounded-xl border border-amber-200/80 bg-white/80 p-3 font-mono text-xs break-all dark:border-amber-800 dark:bg-black/10">
                {{ $localResetUrl }}
            </div>

            <a href="{{ $localResetUrl }}" class="inline-flex items-center rounded-xl bg-amber-600 px-3 py-2 text-sm font-semibold text-white transition hover:bg-amber-700">
                Open reset link
            </a>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-12">

        {{-- ── Left: summary. Ordered above the editor on mobile. ────────── --}}
        <aside class="space-y-5 lg:col-span-4 xl:col-span-3">
            <div class="overflow-hidden rounded-2xl border border-[#EAECF0] bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">

                @foreach([
                    ['Contact', [
                        ['envelope', 'Email', $employee->user?->email ?: 'Not set'],
                        ['phone',    'Phone', $employee->phone ?: 'Not set'],
                    ]],
                    ['Work', [
                        ['clock',           'Shift',   $employee->shift?->name ?? 'No shift assigned'],
                        ['calendar-days',   'Joined',  $employee->joining_date?->format('d M Y') ?? 'Not set'],
                        ['building-office', 'Office',  $employee->office?->name ?? 'Not set'],
                        ['user-circle',     'Manager', $employee->manager?->name ?? 'Not set'],
                    ]],
                ] as [$section, $rows])
                    <div class="px-5 pb-1 pt-4 text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">{{ $section }}</div>
                    <dl class="px-5 pb-3">
                        @foreach($rows as [$icon, $label, $value])
                            {{-- Every row has a real fallback. A blank line reads as a
                                 rendering fault; "Not set" reads as work still to do. --}}
                            <div class="flex items-start gap-3 py-2.5">
                                <flux:icon :name="$icon" class="mt-0.5 size-4 shrink-0 text-[#98A2B3]" />
                                <div class="min-w-0 flex-1">
                                    <dt class="text-xs font-medium text-[#98A2B3]">{{ $label }}</dt>
                                    <dd class="truncate text-sm font-semibold text-[#101828] dark:text-zinc-200" title="{{ $value }}">{{ $value }}</dd>
                                </div>
                            </div>
                        @endforeach
                    </dl>
                @endforeach

                <div class="border-t border-[#EAECF0] px-5 py-4 dark:border-white/10">
                    <h3 class="mb-3 text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">Shortcuts</h3>
                    <div class="grid grid-cols-4 gap-2">
                        @foreach([
                            ['user',           'Profile',    'link', route('employees.profile', $employee)],
                            ['calendar-days',  'Attendance', 'link', route('attendance.employees')],
                            ['folder',         'Documents',  'tab',  'Documents'],
                            ['squares-2x2',    'Activity',   'tab',  'Activity'],
                        ] as [$icon, $label, $kind, $target])
                            <flux:tooltip :content="$label">
                                @if($kind === 'link')
                                    <a href="{{ $target }}" wire:navigate
                                        class="nx-shortcut flex flex-col items-center gap-1.5 rounded-xl bg-[#FFF7F0] p-2.5 text-center hover:bg-orange-100/70 dark:bg-white/5 dark:hover:bg-white/10">
                                        <flux:icon :name="$icon" class="size-4 text-orange-500" />
                                        <span class="text-[10px] font-semibold leading-tight text-[#667085] dark:text-zinc-400">{{ $label }}</span>
                                    </a>
                                @else
                                    <button type="button" wire:click="setTab('{{ $target }}')"
                                        class="nx-shortcut flex w-full flex-col items-center gap-1.5 rounded-xl bg-[#FFF7F0] p-2.5 text-center hover:bg-orange-100/70 dark:bg-white/5 dark:hover:bg-white/10">
                                        <flux:icon :name="$icon" class="size-4 text-orange-500" />
                                        <span class="text-[10px] font-semibold leading-tight text-[#667085] dark:text-zinc-400">{{ $label }}</span>
                                    </button>
                                @endif
                            </flux:tooltip>
                        @endforeach
                    </div>
                </div>
            </div>
        </aside>

        {{-- ── Right: the record ─────────────────────────────────────────── --}}
        <div class="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-[#EAECF0] bg-white lg:col-span-8 xl:col-span-9 shadow-sm dark:border-white/10 dark:bg-zinc-900">

            {{-- Primary: which area of the record. Underlined, weighted. --}}
            <div class="nx-scroll overflow-x-auto border-b border-[#EAECF0] px-4 dark:border-white/10">
                <div class="flex min-w-max items-center gap-1">
                    @foreach($areas as $area => $meta)
                        @php $isArea = $activeArea === $area; @endphp
                        <button type="button" wire:click="setTab('{{ $meta['tabs'][0] }}')"
                            @class([
                                'nx-tab relative flex items-center gap-2 whitespace-nowrap border-b-2 px-3.5 py-3.5 text-sm font-semibold',
                                'border-orange-500 text-orange-600 dark:text-orange-400' => $isArea,
                                'border-transparent text-[#667085] hover:text-[#101828] dark:text-zinc-400 dark:hover:text-zinc-200' => ! $isArea,
                            ])>
                            <flux:icon :name="$meta['icon']" class="size-4" />
                            {{ $area }}
                            @if($area === 'Growth' && $employee->status->value === 'probation')
                                <span class="size-1.5 rounded-full bg-amber-500"></span>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Secondary: which panel within it. Deliberately lighter — pills,
                 no underline, no icons — so the two rows never compete. --}}
            @if(count($areas[$activeArea]['tabs']) > 1)
                <div class="nx-scroll overflow-x-auto border-b border-[#EAECF0] bg-[#FCFCFD] px-4 py-2.5 dark:border-white/10 dark:bg-white/[0.02]">
                    <div class="flex min-w-max items-center gap-1">
                        @foreach($areas[$activeArea]['tabs'] as $tab)
                            @php $isTab = $activeTab === $tab; @endphp
                            <button type="button" wire:click="setTab('{{ $tab }}')"
                                @class([
                                    'nx-tab relative whitespace-nowrap rounded-lg px-3 py-1.5 text-[13px] font-semibold',
                                    'bg-orange-500 text-white shadow-sm' => $isTab,
                                    'text-[#667085] hover:bg-orange-50 hover:text-orange-600 dark:text-zinc-400 dark:hover:bg-white/5' => ! $isTab,
                                ])>
                                {{ $tab }}
                                @if($tab === 'Probation' && $employee->status->value === 'probation')
                                    <span @class(['absolute right-0.5 top-0.5 size-1.5 rounded-full', 'bg-white' => $isTab, 'bg-amber-500' => ! $isTab])></span>
                                @endif
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif

{{-- Tab content --}}
            <div class="flex-1 p-6 md:p-7">
                <form wire:submit="save">

                    {{-- ── General Tab ── --}}
                    @if($activeTab === 'General')
                        <div wire:key="tab-general" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Account Information</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Update and manage employee account details</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="name" label="Full Name" icon="user" required />
                                <flux:input wire:model="email" type="email" label="Email Address" icon="envelope" required />
                                <x-clean-select model="roleId" label="System Role" :live="true"
                                    :options="$roles->map(fn ($roleOption) => ['value' => $roleOption->id, 'label' => $roleOption->name])->all()" />
                                <flux:input wire:model="employee_id" label="Employee ID" icon="identification" placeholder="CNX-0001" required />
                            </div>

                            {{-- HR/manager access scope — only for approver roles --}}
                            @php $selectedBucket = optional($roles->firstWhere('id', (int) $roleId))->legacyBucket()?->value; @endphp
                            @if(in_array($selectedBucket, ['hr_admin', 'manager'], true))
                                <div class="rounded-2xl border border-orange-200/70 bg-orange-50/40 p-5 dark:border-orange-500/20 dark:bg-orange-500/5">
                                    <div class="mb-1 flex items-center gap-2 text-sm font-bold text-zinc-800 dark:text-zinc-100">
                                        <flux:icon.shield-check class="size-4 text-orange-500" /> Attendance Access Scope
                                    </div>
                                    <p class="mb-4 text-xs text-zinc-500 dark:text-zinc-400">Leave both empty for <b>company-wide</b> access. Pick departments and/or shifts to limit what this approver sees and can approve — so two HR admins can own different departments, or split by shift.</p>

                                    <div class="mb-4">
                                        <div class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-zinc-400">Departments</div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($departments as $dept)
                                                <label class="cursor-pointer">
                                                    <input type="checkbox" wire:model.live="scopeDepartments" value="{{ $dept->id }}" class="peer sr-only">
                                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-600 transition peer-checked:border-orange-400 peer-checked:bg-orange-500 peer-checked:text-white dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ $dept->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div>
                                        <div class="mb-1.5 text-[11px] font-bold uppercase tracking-wider text-zinc-400">Shifts</div>
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($shifts as $sh)
                                                <label class="cursor-pointer">
                                                    <input type="checkbox" wire:model.live="scopeShifts" value="{{ $sh->id }}" class="peer sr-only">
                                                    <span class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-600 transition peer-checked:border-orange-400 peer-checked:bg-orange-500 peer-checked:text-white dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300">{{ $sh->name }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </div>

                                    <div class="mt-4 flex items-center gap-1.5 text-xs font-semibold {{ empty($scopeDepartments) && empty($scopeShifts) ? 'text-emerald-600' : 'text-orange-600' }}">
                                        <flux:icon.information-circle class="size-3.5" />
                                        {{ empty($scopeDepartments) && empty($scopeShifts)
                                            ? 'Company-wide — this approver sees every employee.'
                                            : 'Scoped — sees only '.(count($scopeDepartments) ? count($scopeDepartments).' dept(s)' : 'all depts').(count($scopeShifts) ? ' · '.count($scopeShifts).' shift(s)' : '').'.' }}
                                    </div>
                                </div>
                            @endif
                            <div class="flex items-start gap-3 rounded-xl border border-orange-100 bg-orange-50/60 px-4 py-3.5 text-sm text-[#7C4A17] dark:border-orange-500/20 dark:bg-orange-500/5 dark:text-orange-300">
                                <flux:icon.information-circle class="size-4 shrink-0" />
                                These details are used for system access and employee identification.
                            </div>
                            {{-- Sticky so Save stays reachable on a long panel without
                                 scrolling back, and so "what changed" and "commit it"
                                 sit together. --}}
                            <div class="sticky bottom-0 -mx-6 mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-[#EAECF0] bg-white/90 px-6 py-4 backdrop-blur md:-mx-7 md:px-7 dark:border-white/10 dark:bg-zinc-900/90">
                                <div class="flex items-center gap-2 text-xs text-[#98A2B3]">
                                    <span wire:loading.remove wire:target="save" class="flex items-center gap-1.5">
                                        <flux:icon.check-circle class="size-3.5 text-emerald-500" />
                                        Last updated {{ $employee->updated_at->format('d M Y, g:i A') }}
                                    </span>
                                    <span wire:loading wire:target="save" class="flex items-center gap-1.5 font-medium text-orange-600">
                                        <flux:icon.arrow-path class="size-3.5 animate-spin" />
                                        Saving changes…
                                    </span>
                                </div>
                                <div class="flex gap-2">
                                    <flux:button type="button" href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check"
                                        wire:loading.attr="disabled" wire:target="save">
                                        <span wire:loading.remove wire:target="save">Save Changes</span>
                                        <span wire:loading wire:target="save">Saving…</span>
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                    {{-- ── Personal Tab ── --}}
                    @elseif($activeTab === 'Personal')
                        <div wire:key="tab-personal" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Personal Information</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Manage personal and contact details</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="phone" label="Phone Number" icon="phone" placeholder="+91 98765 43210" />
                                <flux:input wire:model="date_of_birth" type="date" label="Date of Birth" />
                                <x-clean-select model="gender" label="Gender" :live="false" placeholder="Select…"
                                    :options="[['value' => 'male', 'label' => 'Male'], ['value' => 'female', 'label' => 'Female'], ['value' => 'other', 'label' => 'Other'], ['value' => 'prefer_not_to_say', 'label' => 'Prefer not to say']]" />
                                <flux:input wire:model="emergency_contact" label="Emergency Contact" icon="phone-arrow-up-right" placeholder="Name & phone" />
                            </div>
                            <flux:textarea wire:model="address" label="Residential Address" rows="2" placeholder="Full residential address" />
                            <flux:field>
                                <flux:label>Profile Photo</flux:label>
                                @if($employee->photo)
                                    <div class="mb-2 flex items-center gap-3">
                                        <img src="{{ asset('storage/'.$employee->photo) }}" class="size-12 rounded-full border border-zinc-200 object-cover" />
                                        <span class="text-xs text-[#98A2B3]">Current photo — upload a new one to replace</span>
                                    </div>
                                @endif
                                <flux:input wire:model="photo" type="file" accept="image/*" class="mt-1" />
                                @error('photo') <flux:error>{{ $message }}</flux:error> @enderror
                            </flux:field>
                            {{-- Sticky so Save stays reachable on a long panel without
                                 scrolling back, and so "what changed" and "commit it"
                                 sit together. --}}
                            <div class="sticky bottom-0 -mx-6 mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-[#EAECF0] bg-white/90 px-6 py-4 backdrop-blur md:-mx-7 md:px-7 dark:border-white/10 dark:bg-zinc-900/90">
                                <div class="flex items-center gap-2 text-xs text-[#98A2B3]">
                                    <span wire:loading.remove wire:target="save" class="flex items-center gap-1.5">
                                        <flux:icon.check-circle class="size-3.5 text-emerald-500" />
                                        Last updated {{ $employee->updated_at->format('d M Y, g:i A') }}
                                    </span>
                                    <span wire:loading wire:target="save" class="flex items-center gap-1.5 font-medium text-orange-600">
                                        <flux:icon.arrow-path class="size-3.5 animate-spin" />
                                        Saving changes…
                                    </span>
                                </div>
                                <div class="flex gap-2">
                                    <flux:button type="button" href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check"
                                        wire:loading.attr="disabled" wire:target="save">
                                        <span wire:loading.remove wire:target="save">Save Changes</span>
                                        <span wire:loading wire:target="save">Saving…</span>
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                    {{-- ── Job Tab ── --}}
                    @elseif($activeTab === 'Job')
                        <div wire:key="tab-job" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Job Information</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Employment details, role assignments, and leave allocations</p>
                            </div>
                            <div class="grid grid-cols-1 gap-5 md:grid-cols-2">
                                <flux:input wire:model="joining_date" type="date" label="Joining Date" required />
                                <flux:input wire:model="probation_end_date" type="date" label="Probation End Date" />
                                <x-clean-select model="status" label="Current Status" :live="false"
                                    :options="collect($statuses)->map(fn ($case) => ['value' => $case->value, 'label' => $case->label()])->all()" />
                                <x-clean-select model="employment_type_id" label="Employment Type" :live="false" placeholder="Select Employment Type…"
                                    :options="$employmentTypes->map(fn ($type) => ['value' => $type->id, 'label' => $type->name])->all()" />
                                <x-clean-select model="work_mode_id" label="Work Mode" :live="false" placeholder="Select Work Mode…"
                                    :options="$workModes->map(fn ($mode) => ['value' => $mode->id, 'label' => $mode->name])->all()" />
                                <x-clean-select model="office_id" label="Office" :live="false" placeholder="Select Office…"
                                    :options="$offices->map(fn ($office) => ['value' => $office->id, 'label' => $office->name])->all()" />
                                <x-clean-select model="department_id" label="Department" :live="false" placeholder="Select Department…"
                                    :options="$departments->map(fn ($dept) => ['value' => $dept->id, 'label' => $dept->name])->all()" />
                                <x-clean-select model="job_title_id" label="Job Title" :live="false" placeholder="Select Job Title…"
                                    :options="$jobTitles->map(fn ($title) => ['value' => $title->id, 'label' => $title->name])->all()" />
                                <x-clean-select model="manager_id" label="Line Manager" :live="false" placeholder="Select Manager…"
                                    :options="$managers->map(fn ($mgr) => ['value' => $mgr->id, 'label' => $mgr->name.' ('.ucfirst($mgr->role->value).')'])->all()" />
                                <x-clean-select model="shift_id" label="Shift" :live="false" placeholder="No Shift Assigned"
                                    :options="$shifts->map(fn ($shift) => ['value' => $shift->id, 'label' => $shift->name.' — '.\Illuminate\Support\Carbon::parse($shift->start_time)->format('g:i A').' to '.\Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A').' (Grace: '.$shift->grace_minutes.'m)'])->all()" />
                                <x-clean-select model="salary_cycle_id" label="Salary Cycle" :live="false" placeholder="Select Salary Cycle…"
                                    :options="$salaryCycles->map(fn ($cycle) => ['value' => $cycle->id, 'label' => $cycle->name])->all()" />
                                <div>
                                    <x-clean-select model="ot_tracking_source" label="OT Tracking Source" :live="true"
                                        :options="[['value' => 'biometric', 'label' => 'Biometric (standard attendance)'], ['value' => 'manual', 'label' => 'Manual (HR-entered)'], ['value' => 'nexflow', 'label' => 'Nexflow (IT/Dev/QA teams)'], ['value' => 'hybrid', 'label' => 'Hybrid (both sources)']]" />
                                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Nexflow/Hybrid enables the Nexflow tab and sync.</p>
                                </div>
                                <div>
                                    <flux:input wire:model.live.debounce.500ms="employee_code" type="number" min="1" max="65535" label="Biometric Device ID"
                                        placeholder="e.g. 17"
                                        description="Device PIN used to match biometric punches. Leave blank if not enrolled." />

                                    {{-- Names the holder instead of a bare "already taken", and offers
                                         the override. The holder is often a deleted employee, who is
                                         invisible in the directory but still reserves the number. --}}
                                    @if($codeConflict)
                                        <div class="mt-2 rounded-xl border border-amber-200 bg-amber-50 p-3 dark:border-amber-500/25 dark:bg-amber-500/10">
                                            <div class="flex items-start gap-2">
                                                <flux:icon.exclamation-triangle class="mt-0.5 size-4 shrink-0 text-amber-500" />
                                                <p class="flex-1 text-xs leading-relaxed text-amber-800 dark:text-amber-300">{{ $codeConflict }}</p>
                                            </div>
                                            <flux:button
                                                wire:click="reassignBiometricCode"
                                                wire:confirm="Move this Device ID to this employee? The current holder loses it and their future punches will stop matching until they are given a new ID."
                                                size="xs" variant="primary" class="mt-2">
                                                Reassign to this employee
                                            </flux:button>
                                        </div>
                                    @endif
                                </div>
                            </div>

                            {{-- Biometric mapping + engine sync --}}
                            <div class="mt-5 rounded-2xl border border-orange-100 bg-orange-50/40 p-4 dark:border-orange-500/20 dark:bg-orange-500/5">
                                <div class="flex flex-wrap items-center justify-between gap-3">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5 text-xs font-bold uppercase tracking-wide text-orange-600 dark:text-orange-300">
                                            <flux:icon.cpu-chip class="size-3.5" /> Biometric mapping &amp; sync
                                        </div>
                                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                            The engine matches punches by <strong>Biometric Device ID</strong>. Save the ID first, then pull this employee's latest computed attendance.
                                        </p>
                                        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-[11px]">
                                            <span class="text-zinc-500 dark:text-zinc-400">Mapped ID:
                                                @if($employee->employee_code)
                                                    <span class="font-mono font-bold text-zinc-800 dark:text-zinc-100">{{ $employee->employee_code }}</span>
                                                @else
                                                    <span class="font-semibold text-amber-600">not enrolled</span>
                                                @endif
                                            </span>
                                            @php
                                                $ss = $employee->sync_status;
                                                [$sc, $sl] = match($ss) {
                                                    'synced' => ['text-emerald-600', 'Synced'],
                                                    'failed' => ['text-rose-500', 'Failed'],
                                                    'removed' => ['text-zinc-400', 'Released'],
                                                    default => ['text-amber-600', 'Pending'],
                                                };
                                            @endphp
                                            <span class="text-zinc-500 dark:text-zinc-400">Device status: <span class="font-bold {{ $sc }}">{{ $sl }}</span></span>
                                            <span class="text-zinc-500 dark:text-zinc-400">Last sync:
                                                <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $employee->last_biometric_sync_at?->diffForHumans() ?? 'never' }}</span>
                                            </span>
                                        </div>
                                    </div>
                                    <div class="flex shrink-0 items-end gap-2">
                                        <div class="w-24">
                                            <flux:input wire:model="syncDays" type="number" min="1" max="30" size="sm" label="Days back" />
                                        </div>
                                        <flux:button type="button" wire:click="syncBiometricNow" wire:loading.attr="disabled" wire:target="syncBiometricNow"
                                            variant="primary" icon="arrow-path" size="sm">
                                            <span wire:loading.remove wire:target="syncBiometricNow">Sync latest</span>
                                            <span wire:loading wire:target="syncBiometricNow">Syncing…</span>
                                        </flux:button>
                                    </div>
                                </div>
                            </div>
                            {{-- Sticky so Save stays reachable on a long panel without
                                 scrolling back, and so "what changed" and "commit it"
                                 sit together. --}}
                            <div class="sticky bottom-0 -mx-6 mt-2 flex flex-wrap items-center justify-between gap-3 border-t border-[#EAECF0] bg-white/90 px-6 py-4 backdrop-blur md:-mx-7 md:px-7 dark:border-white/10 dark:bg-zinc-900/90">
                                <div class="flex items-center gap-2 text-xs text-[#98A2B3]">
                                    <span wire:loading.remove wire:target="save" class="flex items-center gap-1.5">
                                        <flux:icon.check-circle class="size-3.5 text-emerald-500" />
                                        Last updated {{ $employee->updated_at->format('d M Y, g:i A') }}
                                    </span>
                                    <span wire:loading wire:target="save" class="flex items-center gap-1.5 font-medium text-orange-600">
                                        <flux:icon.arrow-path class="size-3.5 animate-spin" />
                                        Saving changes…
                                    </span>
                                </div>
                                <div class="flex gap-2">
                                    <flux:button type="button" href="{{ route('employees.index') }}" wire:navigate variant="ghost">Cancel</flux:button>
                                    <flux:button type="submit" variant="primary" icon="check"
                                        wire:loading.attr="disabled" wire:target="save">
                                        <span wire:loading.remove wire:target="save">Save Changes</span>
                                        <span wire:loading wire:target="save">Saving…</span>
                                    </flux:button>
                                </div>
                            </div>
                        </div>

                    {{-- ── Leave Tab ── --}}
                    @elseif($activeTab === 'Leave')
                        <div wire:key="tab-leave" class="space-y-6">
                            {{-- Header row --}}
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div>
                                    <h3 class="text-base font-bold text-[#101828] dark:text-white">Leave Balance</h3>
                                    <p class="mt-0.5 text-sm text-[#667085]">Centrally managed — allocations are set via Leave Settings.</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    {{-- Year selector --}}
                                    {{-- Leave years, not calendar years. The year runs 1 July to
                                         30 June, so "2026" was never a leave year at all. --}}
                                    <x-clean-select model="leaveBalanceYear" :live="true" :options="$leaveYearOptions" />
                                    @if($canManageLeaveBalance)
                                        <flux:button wire:click="openManageLeaveModal" variant="primary" icon="adjustments-horizontal" size="sm">
                                            Manage Balance
                                        </flux:button>
                                    @endif
                                </div>
                            </div>

                            {{-- Balance summary table --}}
                            <div class="overflow-x-auto rounded-xl border border-[#EAECF0] dark:border-white/10">
                                <table class="w-full text-sm">
                                    <thead>
                                        <tr class="border-b border-[#EAECF0] dark:border-white/10 bg-[#F9FAFB] dark:bg-zinc-800/50">
                                            <th class="px-4 py-3 text-left text-xs font-bold uppercase tracking-wide text-zinc-400">Leave Type</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Fresh</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Carried Fwd</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Used</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Encashed</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Available</th>
                                            <th class="px-4 py-3 text-center text-xs font-bold uppercase tracking-wide text-zinc-400">Pending</th>
                                            <th class="px-4 py-3 text-right text-xs font-bold uppercase tracking-wide text-zinc-400">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @forelse($balanceSummary as $row)
                                            <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition-colors">
                                                <td class="px-4 py-3">
                                                    <div class="flex items-center gap-2">
                                                        <div class="size-2.5 rounded-full shrink-0" style="background-color: {{ $row->leave_type->color }}"></div>
                                                        <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $row->leave_type->name }}</span>
                                                        @if($row->leave_type->is_system_controlled)
                                                            <span class="px-1.5 py-0.5 text-[10px] font-bold bg-zinc-100 dark:bg-zinc-800 text-zinc-500 rounded">System</span>
                                                        @endif
                                                        @if($row->leave_type->is_monthly_accrual)
                                                            <span class="px-1.5 py-0.5 text-[10px] font-bold bg-orange-50 dark:bg-orange-900/20 text-orange-600 dark:text-orange-400 rounded">Accrual</span>
                                                        @endif
                                                    </div>
                                                </td>
                                                {{-- Fresh entitlement, with carried days taken out. allocated_days
                                                     holds both, so printing it beside "Carried Fwd" showed the same
                                                     days in two columns. --}}
                                                <td class="px-4 py-3 text-center font-semibold text-zinc-700 dark:text-zinc-300">{{ $row->fresh > 0 ? $row->fresh : '—' }}</td>
                                                <td class="px-4 py-3 text-center text-zinc-500">{{ $row->carried_forward > 0 ? $row->carried_forward : '—' }}</td>
                                                <td class="px-4 py-3 text-center text-amber-600 dark:text-amber-400 font-semibold">{{ $row->used > 0 ? $row->used : '—' }}</td>
                                                <td class="px-4 py-3 text-center text-zinc-500">{{ $row->encashed > 0 ? $row->encashed : '—' }}</td>
                                                <td class="px-4 py-3 text-center">
                                                    <span class="font-bold {{ $row->available > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400' }}">
                                                        {{ $row->available > 0 ? $row->available : '0' }}
                                                    </span>
                                                </td>
                                                <td class="px-4 py-3 text-center">
                                                    @if($row->pending > 0)
                                                        <span class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded-full text-[11px] font-bold bg-amber-50 dark:bg-amber-900/20 text-amber-700 dark:text-amber-400">
                                                            {{ $row->pending }}d
                                                        </span>
                                                    @else
                                                        <span class="text-zinc-300 dark:text-zinc-600">—</span>
                                                    @endif
                                                </td>
                                                <td class="px-4 py-3 text-right">
                                                    {{-- Each action goes to the service that owns it. None of them is a
                                                         generic credit: carry forward stays traceable to the year it came
                                                         from, and a regularisation goes through the approval chain. --}}
                                                    <div class="flex items-center justify-end gap-1">
                                                        <flux:tooltip content="{{ $row->fresh }} fresh + {{ $row->carried_forward }} carried − {{ $row->used }} used − {{ $row->encashed }} encashed = {{ $row->available }} available">
                                                            <flux:button variant="ghost" size="xs" icon="information-circle" class="text-zinc-400" />
                                                        </flux:tooltip>

                                                        @if($canManageLeaveBalance)
                                                            <flux:tooltip content="Manual balance adjustment — HR corrections only">
                                                                <flux:button wire:click="openManageLeaveModal" variant="ghost" size="xs" icon="adjustments-horizontal" class="text-zinc-400 hover:text-orange-600" />
                                                            </flux:tooltip>
                                                        @endif

                                                        @if($canCarryForward)
                                                            <flux:tooltip content="Carry forward from the previous leave year">
                                                                <flux:button :href="route('time-off.carry-forward', ['employeeId' => $employee->id])" wire:navigate variant="ghost" size="xs" icon="arrow-right-circle" class="text-zinc-400 hover:text-orange-600" />
                                                            </flux:tooltip>
                                                        @endif

                                                        @if($canRegulariseLeave)
                                                            <flux:tooltip content="Regularise a past absence as leave">
                                                                <flux:button :href="route('time-off.regularisation', ['employeeId' => $employee->id])" wire:navigate variant="ghost" size="xs" icon="calendar-days" class="text-zinc-400 hover:text-orange-600" />
                                                            </flux:tooltip>
                                                        @endif
                                                    </div>
                                                </td>
                                            </tr>
                                        @empty
                                            <tr>
                                                <td colspan="8" class="px-4 py-10 text-center text-sm text-zinc-400">
                                                    No leave balances found for {{ $leaveBalanceYear }}. Balance initializes when leave is allocated via Leave Settings.
                                                </td>
                                            </tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>

                            {{-- Adjustment History --}}
                            @if($adjustmentHistory->count() > 0)
                                <div>
                                    <h4 class="mb-3 text-sm font-bold text-zinc-700 dark:text-zinc-300">Adjustment History</h4>
                                    <div class="space-y-2 max-h-64 overflow-y-auto">
                                        @foreach($adjustmentHistory as $log)
                                            <div class="flex items-start gap-3 rounded-xl border border-[#EAECF0] dark:border-white/10 bg-white dark:bg-zinc-900 px-4 py-3">
                                                <div class="mt-0.5 shrink-0">
                                                    @if($log->action === 'credit')
                                                        <span class="flex size-6 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                                            <flux:icon.plus class="size-3.5 text-emerald-600 dark:text-emerald-400" />
                                                        </span>
                                                    @else
                                                        <span class="flex size-6 items-center justify-center rounded-full bg-red-100 dark:bg-red-900/30">
                                                            <flux:icon.minus class="size-3.5 text-red-600 dark:text-red-400" />
                                                        </span>
                                                    @endif
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="flex items-center gap-2 flex-wrap">
                                                        <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                                            {{ ucfirst($log->action) }} {{ $log->days }} day(s)
                                                        </span>
                                                        <span class="text-xs text-[#98A2B3]">{{ $log->leaveType?->name }}</span>
                                                        <span class="text-xs text-[#98A2B3]">·</span>
                                                        <span class="text-xs text-[#98A2B3]">{{ $log->adjusted_at->format('d M Y, h:i A') }}</span>
                                                        <span class="text-xs text-[#98A2B3]">by {{ $log->adjustedByUser?->name }}</span>
                                                    </div>
                                                    <p class="mt-0.5 text-xs text-zinc-500">{{ $log->reason }}</p>
                                                    @if($log->remarks)
                                                        <p class="text-xs text-[#98A2B3] italic">{{ $log->remarks }}</p>
                                                    @endif
                                                    <div class="mt-1 flex items-center gap-2 text-[11px] text-zinc-400">
                                                        <span>Balance: {{ $log->previous_balance }} → <strong class="text-zinc-600 dark:text-zinc-300">{{ $log->new_balance }}</strong></span>
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                        </div>

                    {{-- ── Attendance Tab ── --}}
                    @elseif($activeTab === 'Attendance')
                        <div wire:key="tab-attendance" class="space-y-4">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Attendance</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Most recent 30 attendance records</p>
                            </div>
                            <div class="pulse-table-wrap">
                                <table class="pulse-table">
                                    <thead><tr>
                                        <th class="pulse-th pl-6">Date</th>
                                        <th class="pulse-th">Check In</th>
                                        <th class="pulse-th">Check Out</th>
                                        <th class="pulse-th">Hours</th>
                                        <th class="pulse-th">Flag</th>
                                    </tr></thead>
                                    <tbody>
                                        @forelse($attendanceRecords as $rec)
                                            <tr>
                                                <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($rec->date)->format('d M Y') }}</td>
                                                <td class="pulse-td">{{ $rec->check_in?->format('H:i') ?? '—' }}</td>
                                                <td class="pulse-td">{{ $rec->check_out?->format('H:i') ?? '—' }}</td>
                                                <td class="pulse-td">{{ $rec->total_hours ? (float) $rec->total_hours : '—' }}</td>
                                                <td class="pulse-td">
                                                    @if($rec->is_late)<span class="badge-late">LATE</span>@else<span class="badge-on_time">ON TIME</span>@endif
                                                </td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="5" class="pulse-table__empty">No attendance records.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    {{-- ── OT Tab ── --}}
                    @elseif($activeTab === 'OT')
                        <div wire:key="tab-ot" class="space-y-4">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-[#101828] dark:text-white">Overtime</h3>
                                    <p class="mt-0.5 text-sm text-[#667085]">Recorded OT hours</p>
                                </div>
                                <div class="text-right">
                                    <div class="text-2xl font-black text-zinc-900 dark:text-white">{{ (float) $otRecords->sum('ot_hours') }}h</div>
                                    <div class="text-[11px] text-zinc-400">total (last 30 records)</div>
                                </div>
                            </div>
                            <div class="pulse-table-wrap">
                                <table class="pulse-table">
                                    <thead><tr>
                                        <th class="pulse-th pl-6">Work Date</th>
                                        <th class="pulse-th">OT Hours</th>
                                    </tr></thead>
                                    <tbody>
                                        @forelse($otRecords as $ot)
                                            <tr>
                                                <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($ot->work_date)->format('d M Y') }}</td>
                                                <td class="pulse-td">{{ (float) $ot->ot_hours }}h</td>
                                            </tr>
                                        @empty
                                            <tr><td colspan="2" class="pulse-table__empty">No overtime records.</td></tr>
                                        @endforelse
                                    </tbody>
                                </table>
                            </div>
                        </div>

                    {{-- ── Performance Tab ── --}}
                    @elseif($activeTab === 'Performance')
                        <div wire:key="tab-performance" class="space-y-6">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Performance Trend</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Review ratings and KPI scorecards over time</p>
                            </div>
                            @if($reviewHistory->isNotEmpty())
                                <div class="flex flex-wrap gap-3">
                                    @foreach($reviewHistory->take(8) as $rev)
                                        <div class="rounded-xl border border-[#EAECF0] px-4 py-3 dark:border-zinc-800">
                                            <div class="text-xs text-[#98A2B3]">{{ $rev->cycle?->name ?? 'Cycle' }}</div>
                                            <div class="text-xl font-black text-zinc-900 dark:text-white">{{ $rev->overall_rating ?? '—' }}<span class="text-xs text-[#98A2B3]">/5</span></div>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                            <div>
                                <h4 class="mb-2 text-sm font-bold text-zinc-700 dark:text-zinc-300">KPI History</h4>
                                <div class="pulse-table-wrap">
                                    <table class="pulse-table">
                                        <thead><tr>
                                            <th class="pulse-th pl-6">Cycle</th>
                                            <th class="pulse-th">Final Score</th>
                                            <th class="pulse-th">Grade</th>
                                        </tr></thead>
                                        <tbody>
                                            @forelse($kpiHistory as $sc)
                                                <tr>
                                                    <td class="pulse-td pl-6 font-medium text-zinc-900 dark:text-white">{{ $sc->cycle?->name ?? '—' }}</td>
                                                    <td class="pulse-td">{{ $sc->final_score }}</td>
                                                    <td class="pulse-td">{{ $sc->grade ?? '—' }}</td>
                                                </tr>
                                            @empty
                                                <tr><td colspan="3" class="pulse-table__empty">No scorecards yet.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>

                    {{-- ── Warnings Tab ── --}}
                    @elseif($activeTab === 'Warnings')
                        <div wire:key="tab-warnings" class="space-y-4">
                            <h3 class="text-base font-bold text-[#101828] dark:text-white">Warning Letters</h3>
                            <div class="space-y-2">
                                @forelse($warningHistory as $w)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-[#EAECF0] px-4 py-3 dark:border-zinc-800">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $w->warning_type)) }}</div>
                                            <div class="text-xs text-[#98A2B3]">{{ $w->issue_date ? \Illuminate\Support\Carbon::parse($w->issue_date)->format('d M Y') : '—' }}</div>
                                        </div>
                                        <span class="badge-warning-{{ $w->status }}">{{ strtoupper(str_replace('_', ' ', $w->status)) }}</span>
                                    </div>
                                @empty
                                    <p class="py-8 text-center text-sm text-zinc-400">No warning letters.</p>
                                @endforelse
                            </div>
                        </div>

                    {{-- ── PIP Tab ── --}}
                    @elseif($activeTab === 'PIP')
                        <div wire:key="tab-pip" class="space-y-4">
                            <h3 class="text-base font-bold text-[#101828] dark:text-white">Performance Improvement Plans</h3>
                            <div class="space-y-2">
                                @forelse($pipHistory as $pip)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-[#EAECF0] px-4 py-3 dark:border-zinc-800">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">PIP · {{ $pip->start_date ? \Illuminate\Support\Carbon::parse($pip->start_date)->format('d M Y') : '—' }} &rarr; {{ $pip->end_date ? \Illuminate\Support\Carbon::parse($pip->end_date)->format('d M Y') : '—' }}</div>
                                            @if($pip->outcome)<div class="text-xs text-[#98A2B3]">Outcome: {{ ucfirst($pip->outcome) }}</div>@endif
                                        </div>
                                        <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-bold uppercase text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ str_replace('_', ' ', $pip->status) }}</span>
                                    </div>
                                @empty
                                    <p class="py-8 text-center text-sm text-zinc-400">No PIP records.</p>
                                @endforelse
                            </div>
                        </div>

                    {{-- ── Promotions Tab ── --}}
                    @elseif($activeTab === 'Promotions')
                        <div wire:key="tab-promotions" class="space-y-4">
                            <h3 class="text-base font-bold text-[#101828] dark:text-white">Promotions &amp; Recommendations</h3>
                            <div class="space-y-2">
                                @forelse($promotionHistory as $promo)
                                    <div class="flex items-center justify-between gap-3 rounded-xl border border-[#EAECF0] px-4 py-3 dark:border-zinc-800">
                                        <div class="min-w-0">
                                            <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ ucwords(str_replace('_', ' ', $promo->recommendation_type)) }}</div>
                                            <div class="text-xs text-[#98A2B3]">{{ $promo->current_role ?? '' }} @if($promo->proposed_role)&rarr; {{ $promo->proposed_role }}@endif</div>
                                        </div>
                                        <span class="rounded-full bg-zinc-100 px-2.5 py-0.5 text-[11px] font-bold uppercase text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ str_replace('_', ' ', $promo->status) }}</span>
                                    </div>
                                @empty
                                    <p class="py-8 text-center text-sm text-zinc-400">No promotion recommendations.</p>
                                @endforelse
                            </div>
                        </div>

                    {{-- ── Timeline Tab ── --}}
                    @elseif($activeTab === 'Timeline')
                        <div wire:key="tab-timeline" class="space-y-4">
                            <h3 class="text-base font-bold text-[#101828] dark:text-white">Career Timeline</h3>
                            <div class="relative space-y-4 border-l border-zinc-200 pl-6 dark:border-zinc-700">
                                @forelse($timeline as $event)
                                    <div class="relative">
                                        <span class="absolute -left-[26px] top-1 size-3 rounded-full bg-brand-500 ring-4 ring-white dark:ring-zinc-900"></span>
                                        <div class="text-xs text-[#98A2B3]">{{ $event->event_date ? \Illuminate\Support\Carbon::parse($event->event_date)->format('d M Y') : '' }}</div>
                                        <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $event->title }}</div>
                                        @if($event->description)<div class="text-xs text-zinc-500">{{ $event->description }}</div>@endif
                                    </div>
                                @empty
                                    <p class="py-8 text-center text-sm text-zinc-400">No timeline events.</p>
                                @endforelse
                            </div>
                        </div>

                    {{-- ── Probation Tab ── --}}
                    @elseif($activeTab === 'Probation')
                        <div wire:key="tab-probation" class="space-y-6">
                            <div class="flex items-start justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-[#101828] dark:text-white">Probation Management</h3>
                                    <p class="mt-0.5 text-sm text-[#667085]">Review and action this employee's probation period.</p>
                                </div>
                                @if($employee->status->value === 'probation')
                                    <flux:badge color="amber">On Probation</flux:badge>
                                @else
                                    <flux:badge color="green">Permanent</flux:badge>
                                @endif
                            </div>

                            <div class="grid grid-cols-2 gap-4 rounded-xl border border-[#EAECF0] bg-[#F9FAFB] p-4 text-sm dark:border-zinc-800 dark:bg-zinc-900">
                                <div>
                                    <div class="mb-1 text-zinc-400">Joining Date</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">{{ $employee->joining_date?->format('d M Y') ?? 'Not set' }}</div>
                                </div>
                                <div>
                                    <div class="mb-1 text-zinc-400">Probation End Date</div>
                                    <div class="font-bold text-zinc-900 dark:text-white">
                                        {{ $employee->probation_end_date?->format('d M Y') ?? 'Not set' }}
                                    </div>
                                </div>
                                @if($employee->probation_extension_reason)
                                    <div class="col-span-2">
                                        <div class="mb-1 text-zinc-400">Extension Reason</div>
                                        <div class="text-zinc-700 dark:text-zinc-300">{{ $employee->probation_extension_reason }}</div>
                                    </div>
                                @endif
                            </div>

                            @if($employee->status->value === 'probation')
                                <div class="space-y-3 rounded-xl border border-emerald-200 bg-emerald-50 p-5 dark:border-emerald-900/40 dark:bg-emerald-950/20">
                                    <h4 class="font-bold text-emerald-800 dark:text-emerald-300">Confirm Probation</h4>
                                    <p class="text-sm text-emerald-700 dark:text-emerald-400">Mark as permanent. Status will change to <strong>Active</strong>.</p>
                                    <flux:button type="button" wire:click="confirmProbation"
                                        wire:confirm="Confirm probation? This will mark the employee as permanent."
                                        variant="primary" icon="check">
                                        Confirm &amp; Make Permanent
                                    </flux:button>
                                </div>
                                <div class="space-y-4 rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/40 dark:bg-amber-950/20">
                                    <h4 class="font-bold text-amber-800 dark:text-amber-300">Extend Probation</h4>
                                    <p class="text-sm text-amber-700 dark:text-amber-400">Set a new end date. Line manager will be notified.</p>
                                    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                                        <flux:input wire:model="extend_end_date" type="date" label="New Probation End Date" required />
                                    </div>
                                    <flux:textarea wire:model="extend_reason" label="Reason for Extension" rows="2" required placeholder="Describe the reason..." />
                                    <flux:button type="button" wire:click="extendProbation" icon="clock">Extend Probation</flux:button>
                                </div>
                            @else
                                <div class="flex flex-col items-center justify-center py-10 text-center">
                                    <div class="mb-3 flex size-14 items-center justify-center rounded-2xl bg-emerald-50 dark:bg-emerald-900/20">
                                        <flux:icon.check-circle class="size-8 text-emerald-500" />
                                    </div>
                                    <h3 class="font-bold text-zinc-900 dark:text-white">Probation Completed</h3>
                                    <p class="mt-1 text-sm text-[#667085]">This employee has completed their probation period.</p>
                                </div>
                            @endif
                        </div>

                    {{-- ── Payroll Tab ── --}}
                    @elseif($activeTab === 'Payroll')
                        @php
                            $grossSalary     = $employee->salaries->where('component.type', 'earning')->sum('amount');
                            $totalDeductions = $employee->salaries->where('component.type', 'deduction')->sum('amount');
                            $netSalary       = $grossSalary - $totalDeductions;
                        @endphp
                        <div wire:key="tab-payroll" class="space-y-6">
                            <div class="flex items-center justify-between">
                                <div>
                                    <h3 class="text-base font-bold text-[#101828] dark:text-white">Payroll Summary</h3>
                                    <p class="mt-0.5 text-sm text-[#667085]">Manage salary structure and compensation for this employee</p>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold uppercase text-zinc-400">{{ $employee->salaryCycle?->name ?? 'Default Cycle' }}</span>
                                    <flux:button wire:click="openAddSalary" variant="primary" icon="plus" size="sm">Add Component</flux:button>
                                </div>
                            </div>

                            {{-- Net compensation card --}}
                            <div class="rounded-2xl border border-[#EAECF0] bg-white p-5 dark:border-zinc-800 dark:bg-zinc-800/50">
                                <div class="flex flex-col items-start justify-between gap-3 md:flex-row md:items-center">
                                    <div>
                                        <div class="text-sm font-bold text-zinc-900 dark:text-white">Total Net Compensation</div>
                                        <div class="text-xs text-[#98A2B3]">Based on assigned salary components</div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-2xl font-black text-orange-600">₹{{ number_format($netSalary, 2) }}</div>
                                        <div class="flex gap-3 text-xs">
                                            <span class="text-emerald-500">Gross: ₹{{ number_format($grossSalary, 2) }}</span>
                                            <span class="text-rose-500">Deductions: -₹{{ number_format($totalDeductions, 2) }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            {{-- Earnings --}}
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-black uppercase tracking-widest text-emerald-500">Earnings</h4>
                                    <span class="text-xs text-[#98A2B3]">₹{{ number_format($grossSalary, 2) }}</span>
                                </div>
                                @forelse($employee->salaries->where('component.type', 'earning') as $salary)
                                    <div class="flex items-center justify-between rounded-xl border border-[#EAECF0] bg-[#F9FAFB] px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-black text-zinc-900 dark:text-white">₹{{ number_format($salary->amount, 2) }}</span>
                                            <flux:button wire:click="openEditSalary({{ $salary->id }})" variant="ghost" size="xs" icon="pencil" class="text-zinc-400 hover:text-orange-500" />
                                            <flux:button wire:click="removeSalary({{ $salary->id }})" wire:confirm="Remove this earning component?" variant="ghost" size="xs" icon="trash" class="text-zinc-400 hover:text-red-500" />
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-zinc-200 py-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
                                        No earnings assigned — click <strong>Add Component</strong> to get started.
                                    </div>
                                @endforelse
                            </div>

                            {{-- Deductions --}}
                            <div class="space-y-2">
                                <div class="flex items-center justify-between">
                                    <h4 class="text-xs font-black uppercase tracking-widest text-rose-500">Deductions</h4>
                                    <span class="text-xs text-[#98A2B3]">-₹{{ number_format($totalDeductions, 2) }}</span>
                                </div>
                                @forelse($employee->salaries->where('component.type', 'deduction') as $salary)
                                    <div class="flex items-center justify-between rounded-xl border border-[#EAECF0] bg-[#F9FAFB] px-4 py-3 dark:border-zinc-800 dark:bg-zinc-900">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-white">{{ $salary->component->name }}</span>
                                        <div class="flex items-center gap-3">
                                            <span class="text-sm font-black text-rose-600">-₹{{ number_format($salary->amount, 2) }}</span>
                                            <flux:button wire:click="openEditSalary({{ $salary->id }})" variant="ghost" size="xs" icon="pencil" class="text-zinc-400 hover:text-orange-500" />
                                            <flux:button wire:click="removeSalary({{ $salary->id }})" wire:confirm="Remove this deduction component?" variant="ghost" size="xs" icon="trash" class="text-zinc-400 hover:text-red-500" />
                                        </div>
                                    </div>
                                @empty
                                    <div class="rounded-xl border border-dashed border-zinc-200 py-6 text-center text-sm text-zinc-400 dark:border-zinc-700">
                                        No deductions assigned.
                                    </div>
                                @endforelse
                            </div>

                            <div class="flex justify-end border-t border-[#EAECF0] pt-4 dark:border-zinc-800">
                                <flux:button href="{{ route('payroll.components') }}" wire:navigate variant="ghost" icon="cog-6-tooth" size="sm">
                                    Manage Global Components
                                </flux:button>
                            </div>
                        </div>

                        {{-- Salary component modal --}}
                        @if($showSalaryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showSalaryModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showSalaryModal', false)"></div>
            <div class="relative w-full max-w-sm bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.set('showSalaryModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                            <div class="space-y-5">
                                <div>
                                    <flux:heading size="lg">{{ $editingSalaryId ? 'Edit' : 'Add' }} Salary Component</flux:heading>
                                    <flux:subheading>For {{ $employee->user->name }}</flux:subheading>
                                </div>

                                <div class="space-y-4">
                                    <flux:field>
                                        <x-clean-select model="salaryComponentId" label="Component" :live="false" placeholder="Select component…"
                                            :options="array_merge(
                                                $salaryComponents->where('type', 'earning')->map(fn ($sc) => ['value' => $sc->id, 'label' => $sc->name.' (Earning)'])->values()->all(),
                                                $salaryComponents->where('type', 'deduction')->map(fn ($sc) => ['value' => $sc->id, 'label' => $sc->name.' (Deduction)'])->values()->all()
                                            )" />
                                        @error('salaryComponentId') <flux:error>{{ $message }}</flux:error> @enderror
                                    </flux:field>

                                    <flux:input wire:model="salaryAmount" type="number" step="0.01" min="0" label="Amount (₹)" placeholder="e.g. 25000" required />
                                    @error('salaryAmount') <p class="text-xs text-red-500 -mt-2">{{ $message }}</p> @enderror
                                </div>

                                <div class="flex justify-end gap-3 border-t border-[#EAECF0] pt-4 dark:border-zinc-800">
                                    <button type="button" @click="$wire.set('showSalaryModal', false)" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-[#F9FAFB] dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                                    <flux:button
                                        wire:click="saveSalary"
                                        wire:loading.attr="disabled"
                                        wire:target="saveSalary"
                                        variant="primary"
                                        icon="check"
                                    >Save</flux:button>
                                </div>
                            </div>
                        
            </div>
        </div>
    @endif

                    {{-- ── Documents Tab ── --}}
                    @elseif($activeTab === 'Documents')
                        <div wire:key="tab-documents" class="space-y-5">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Documents</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Employee documents and files</p>
                            </div>
                            <livewire:employees.employee-documents :employee="$employee" :key="'docs-'.$employee->id" />
                        </div>

                    {{-- ── Activity Tab ── --}}
                    @elseif($activeTab === 'Activity')
                        <div wire:key="tab-activity" class="space-y-5">
                            <div>
                                <h3 class="text-base font-bold text-[#101828] dark:text-white">Activity Log</h3>
                                <p class="mt-0.5 text-sm text-[#667085]">Recent actions and changes for this employee</p>
                            </div>
                            <div class="flex flex-col items-center justify-center py-16 text-center opacity-60">
                                <flux:icon.clock class="size-10 text-zinc-400 mb-3" />
                                <p class="text-sm text-[#667085]">Activity log coming soon.</p>
                            </div>
                        </div>

                    {{-- ── Nexflow Tab ── --}}
                    @elseif($activeTab === 'Nexflow')
                        <livewire:employees.nexflow-activity :employee="$employee" :key="'nexflow-'.$employee->id" />
                    @endif

                </form>
            </div>
        </div>
    </div>

    {{-- Manage Leave Balance Modal --}}
    @if($showManageLeaveModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeManageLeaveModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="closeManageLeaveModal"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">
                <button type="button" wire:click="closeManageLeaveModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

                <div>
                    {{-- Named for what it is. It was "Manage Leave Balance", which read
                         as the way to do anything to a balance — including carrying
                         days forward, where it would have credited days with no link
                         to the year they came from. --}}
                    <h2 class="text-base font-bold text-[#101828] dark:text-white">Manual Balance Adjustment</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">For {{ $employee->user->name }} · {{ $leaveBalanceYear }}</p>
                </div>

                <div class="flex items-start gap-3 rounded-xl border border-orange-100 bg-orange-50/60 px-3.5 py-3 text-xs text-[#7C4A17] dark:border-orange-500/20 dark:bg-orange-500/5 dark:text-orange-300">
                    <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
                    <span>
                        Use <a href="{{ route('time-off.carry-forward') }}" wire:navigate class="font-semibold underline">Carry Forward</a>
                        for previous-year entitlement — it stays traceable to the year it came from.
                        Use this only for HR corrections.
                    </span>
                </div>

                <div class="space-y-4">
                    {{-- Leave Type --}}
                    <flux:field>
                        <x-clean-select model="leaveAdjustTypeId" label="Leave Type" :required="true" :live="false" placeholder="Select leave type…"
                            :options="$adjustableLeaveTypes->map(fn ($lt) => ['value' => $lt->id, 'label' => $lt->name])->all()" />
                        @error('leaveAdjustTypeId') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>

                    {{-- Action --}}
                    <div>
                        <flux:label>Action <span class="text-red-500">*</span></flux:label>
                        <div class="mt-1.5 flex gap-4">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="leaveAdjustAction" value="credit" class="accent-emerald-600" />
                                <span class="text-sm font-semibold text-emerald-700 dark:text-emerald-400">Credit (Add Days)</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="leaveAdjustAction" value="debit" class="accent-red-600" />
                                <span class="text-sm font-semibold text-red-600 dark:text-red-400">Debit (Remove Days)</span>
                            </label>
                        </div>
                    </div>

                    {{-- Days --}}
                    <flux:input wire:model="leaveAdjustDays" type="number" step="0.5" min="0.5" label="Days" placeholder="e.g. 2" required />
                    @error('leaveAdjustDays') <p class="text-xs text-red-500 -mt-2">{{ $message }}</p> @enderror

                    {{-- Reason --}}
                    <flux:field>
                        <flux:label>Reason <span class="text-red-500">*</span></flux:label>
                        <flux:textarea wire:model="leaveAdjustReason" rows="2" placeholder="Reason for the adjustment (min 5 chars)…" required />
                        @error('leaveAdjustReason') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>

                    {{-- Remarks --}}
                    <flux:field>
                        <flux:label>Remarks <span class="text-xs font-normal text-zinc-400">(optional)</span></flux:label>
                        <flux:textarea wire:model="leaveAdjustRemarks" rows="2" placeholder="Additional notes…" />
                    </flux:field>
                </div>

                <div class="flex justify-end gap-3 border-t border-[#EAECF0] dark:border-white/10 pt-4">
                    <button type="button" wire:click="closeManageLeaveModal"
                        class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-[#F9FAFB] dark:hover:bg-zinc-700 transition-colors">
                        Cancel
                    </button>
                    <flux:button
                        wire:click="submitLeaveAdjustment"
                        wire:loading.attr="disabled"
                        wire:target="submitLeaveAdjustment"
                        variant="primary"
                        icon="check">
                        <span wire:loading.remove wire:target="submitLeaveAdjustment">Apply Adjustment</span>
                        <span wire:loading wire:target="submitLeaveAdjustment">Applying…</span>
                    </flux:button>
                </div>
            </div>
        </div>
    @endif

    {{-- Send Email Modal --}}
    @if($showEmailModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeEmailModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" wire:click="closeEmailModal"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">
                <button type="button" wire:click="closeEmailModal"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div>
                    <h2 class="text-base font-bold text-[#101828] dark:text-white">Send Email</h2>
                    <p class="text-sm text-zinc-500 mt-0.5">To: {{ $employee->user->email }}</p>
                </div>
                <div class="space-y-4">
                    <flux:field>
                        <flux:label>Subject</flux:label>
                        <flux:input wire:model="emailSubject" placeholder="e.g. Important Update" required />
                        @error('emailSubject') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:label>Message</flux:label>
                        <flux:textarea wire:model="emailBody" placeholder="Write your message here…" rows="5" required />
                        @error('emailBody') <p class="text-xs text-red-500">{{ $message }}</p> @enderror
                    </flux:field>
                </div>
                <div class="flex justify-end gap-3 border-t border-[#EAECF0] dark:border-white/10 pt-4">
                    <button type="button" wire:click="closeEmailModal"
                        class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-[#F9FAFB] transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="sendEmail"
                        wire:loading.attr="disabled" wire:target="sendEmail"
                        class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors disabled:opacity-60">
                        <flux:icon.paper-airplane class="size-4" />
                        <span wire:loading.remove wire:target="sendEmail">Send Email</span>
                        <span wire:loading wire:target="sendEmail">Sending…</span>
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- One-time temporary-password reveal --}}
    <flux:modal wire:model.self="showCredentialsModal" class="w-full max-w-md">
        <div class="space-y-4"
             x-data="{ copied: false, copy(t){ navigator.clipboard.writeText(t); this.copied = true; setTimeout(() => this.copied = false, 1500); } }">
            <div class="flex items-center gap-2">
                <flux:icon.key class="size-6 text-orange-500" />
                <flux:heading size="lg">Temporary password set</flux:heading>
            </div>
            <flux:subheading>Emailed to {{ $employee->user->email }} (if the Welcome Email is enabled). Copy it to share directly.</flux:subheading>

            <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-[#F9FAFB] p-4 dark:border-white/10 dark:bg-zinc-800/50">
                <code class="rounded-lg bg-white px-2 py-1 font-mono text-sm text-zinc-900 dark:bg-zinc-900 dark:text-white">{{ $generatedPassword }}</code>
                <button type="button" x-on:click="copy(@js($generatedPassword))"
                    class="rounded-lg bg-orange-500 px-2.5 py-1 text-xs font-bold text-white transition hover:bg-orange-600"
                    x-text="copied ? 'Copied!' : 'Copy'"></button>
            </div>

            <div class="flex justify-end">
                <flux:button wire:click="$set('showCredentialsModal', false)" variant="primary">Done</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
