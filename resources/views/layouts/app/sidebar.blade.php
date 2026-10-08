@props(['title' => null])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    @include('partials.head')
</head>

<body class="min-h-screen bg-[#FAFAFA] antialiased dark:bg-[#0B1220]">

    @php
        $user = auth()->user();
        $employee = $user->employee;
        // Built from what this user can open (route middleware + page checks),
        // never from the role's name. See App\Services\Navigation\Sidebar.
        $nav = app(\App\Services\Navigation\Sidebar::class);
        $navGroups = $nav->groups($user);
        $navSettings = $nav->settings($user);
        // Nothing beyond self-service → the admin-configurable employee menu.
        $pureEmployee = $nav->isSelfServiceOnly($user);
        $payslipsOn = app(\App\Services\ModuleFeatureService::class)->payslipsEnabled();

        // Premium primary accent — orange across all roles (#F97316).
        $roleColor = '#f97316';
        $roleLabel = $user->displayRoleName();

        $unread = $user->unreadNotifications()->count();

        $searchLinks = collect($nav->links($user))
            ->map(fn (array $l) => ['label' => $l['label'], 'route' => $l['url'], 'caption' => $l['caption']])
            ->unique('route')
            ->values();
    @endphp

    <flux:sidebar sticky collapsible
        style="--role-accent: {{ $roleColor }}; --color-accent: {{ $roleColor }}; --color-accent-content: {{ $roleColor }}; --color-accent-foreground: #ffffff;"
        class="pulse-sidebar border-e border-[#F3E8DD] bg-[#FFF8F1]">

        {{-- Brand row, ChatGPT-style. Open: the wordmark on the left and the
             fold button on the right. Folded: one 40px square holding the
             square icon; hovering (or focusing) it swaps the icon for the
             unfold button. Each theme shows its own artwork (Settings →
             General). flux:sidebar.brand is not used: it crops a wide mark.
             The role chip that used to sit under the logo is gone — the role
             is on the account card at the bottom and in the header. --}}
        <div data-pulse-sidebar-brand-row
            class="flex h-12 shrink-0 items-center justify-between gap-2 ps-2 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:ps-0">
            <a href="{{ route('dashboard') }}" wire:navigate data-flux-sidebar-brand
                class="flex min-w-0 items-center rounded-lg focus-visible:outline-2 focus-visible:outline-orange-500 in-data-flux-sidebar-collapsed-desktop:hidden">
                <x-brand-logo size="h-12 w-auto max-w-[190px]" /></a>

            {{-- Anyone can fold the rail on desktop; Flux remembers the state
                 per browser, so the control is always offered. On mobile the
                 sidebar is a drawer and this row only shows the wordmark. --}}
            <div class="group/rail relative flex size-10 shrink-0 items-center justify-center max-lg:hidden">
                <span class="pointer-events-none hidden size-7 items-center justify-center transition-opacity duration-150 in-data-flux-sidebar-collapsed-desktop:flex in-data-flux-sidebar-collapsed-desktop:group-hover/rail:opacity-0 in-data-flux-sidebar-collapsed-desktop:group-has-[:focus-visible]/rail:opacity-0">
                    <x-brand-logo variant="mark" size="size-7" />
                </span>
                <flux:sidebar.collapse
                    class="in-data-flux-sidebar-collapsed-desktop:inset-0 in-data-flux-sidebar-collapsed-desktop:h-10 in-data-flux-sidebar-collapsed-desktop:w-10 in-data-flux-sidebar-collapsed-desktop:group-hover/rail:opacity-100! in-data-flux-sidebar-collapsed-desktop:group-has-[:focus-visible]/rail:opacity-100!" />
            </div>
        </div>

        <flux:sidebar.nav class="px-2">
            @auth

                {{-- ════════════════════════════════════════════
                EMPLOYEE SIDEBAR
                ════════════════════════════════════════════ --}}
                @if($pureEmployee)

                    @php
                        $pendingLeave = $employee?->leaveRequests()->whereIn('status', ['pending', 'pending_hr'])->count() ?? 0;
                        $pendingOt = $employee?->otRequests()->pending()->count() ?? 0;
                        $activeWarnings = $employee?->activeWarnings()->count() ?? 0;
                        $hasActivePip = $employee?->activePip()->exists() ?? false;
                        $showNexflow = $employee && in_array($employee->ot_tracking_source, ['nexflow', 'hybrid'], true);

                        $pendingDocs = 0;
                        if ($employee) {
                            $pendingDocs = \App\Models\Document::whereNull('parent_id')
                                ->where('requires_acknowledgement', true)
                                ->where(function ($q) use ($employee) {
                                    // Same rule as the Documents page: a policy addressed to one person is theirs.
                                    $q->where('visibility', 'all')
                                        ->orWhere(fn ($policy) => $policy->where('category', 'policy')->whereNull('employee_id'))
                                        ->orWhere('employee_id', $employee->id);
                                })
                                ->whereDoesntHave('acknowledgements', fn ($q) => $q->where('employee_id', $employee->id))
                                ->count();
                        }
                    @endphp

                    {{-- Employee menu — order/visibility/labels are admin-controllable
                         (Settings > Sidebar Menu). Fail-open: unknown items show by default. --}}
                    @php
                        $employeeMenu = app(\App\Services\EmployeeMenu::class)->visible();
                        $menuBadges = [
                            'leave' => $pendingLeave,
                            'overtime' => $pendingOt,
                            'documents' => $pendingDocs,
                            'inbox' => $unread,
                        ];
                    @endphp

                    @foreach($employeeMenu as $mi)
                        @switch($mi['key'])
                            @case('performance')
                                <flux:sidebar.group :heading="$mi['label']" icon="arrow-trending-up" :expandable="true"
                                    :expanded="request()->routeIs('performance.dashboard', 'performance.my', 'performance.my-warnings', 'performance.pip.my', 'performance.promotions.my')">
                                    <flux:sidebar.item :href="route('performance.dashboard')" :current="request()->routeIs('performance.dashboard')"
                                        wire:navigate>
                                        My Performance
                                    </flux:sidebar.item>
                                    <flux:sidebar.item :href="route('performance.my')" :current="request()->routeIs('performance.my')"
                                        wire:navigate>
                                        My Review
                                    </flux:sidebar.item>
                                    @php $pendingReviewTasks = \App\Models\ReviewParticipant::where('reviewer_id', auth()->id())->where('status', 'pending')->count(); @endphp
                                    @if($pendingReviewTasks > 0)
                                        <flux:sidebar.item :href="route('performance.review-tasks')"
                                            :current="request()->routeIs('performance.review-tasks')" wire:navigate>
                                            <div class="flex items-center gap-2">
                                                Review Tasks
                                                <span class="inline-flex items-center justify-center rounded-full bg-violet-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $pendingReviewTasks > 9 ? '9+' : $pendingReviewTasks }}</span>
                                            </div>
                                        </flux:sidebar.item>
                                    @endif
                                    <flux:sidebar.item :href="route('performance.my-warnings')"
                                        :current="request()->routeIs('performance.my-warnings')" wire:navigate>
                                        <div class="flex items-center gap-2">
                                            My Warnings
                                            @if($activeWarnings > 0)
                                                <span
                                                    class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">{{ $activeWarnings > 9 ? '9+' : $activeWarnings }}</span>
                                            @endif
                                        </div>
                                    </flux:sidebar.item>
                                    <flux:sidebar.item :href="route('performance.pip.my')"
                                        :current="request()->routeIs('performance.pip.my')" wire:navigate>
                                        <div class="flex items-center gap-2">
                                            My Improvement Plan
                                            @if($hasActivePip)
                                                <span
                                                    class="inline-flex items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-bold text-white">1</span>
                                            @endif
                                        </div>
                                    </flux:sidebar.item>
                                    <flux:sidebar.item :href="route('performance.promotions.my')"
                                        :current="request()->routeIs('performance.promotions.my')" wire:navigate>
                                        My Promotions
                                    </flux:sidebar.item>
                                </flux:sidebar.group>
                                @break

                            @case('development')
                                <flux:sidebar.group :heading="$mi['label']" icon="academic-cap" :expandable="true"
                                    :expanded="request()->routeIs('performance.goals', 'performance.my-kpis')">
                                    <flux:sidebar.item :href="route('performance.goals')" :current="request()->routeIs('performance.goals')"
                                        wire:navigate>
                                        My Goals
                                    </flux:sidebar.item>
                                    <flux:sidebar.item :href="route('performance.my-kpis')"
                                        :current="request()->routeIs('performance.my-kpis')" wire:navigate>
                                        My KPIs
                                    </flux:sidebar.item>
                                </flux:sidebar.group>
                                @break

                            @case('payroll')
                                {{-- reimbursements not shown (requires run-payroll, 403 for employee) --}}
                                <flux:sidebar.group :heading="$mi['label']" icon="banknotes" :expandable="true"
                                    :expanded="request()->routeIs('payroll.payslips', 'operations.expenses')">
                                    @if($payslipsOn)
                                    <flux:sidebar.item :href="route('payroll.payslips')" :current="request()->routeIs('payroll.payslips')"
                                        wire:navigate>
                                        My Payslips
                                    </flux:sidebar.item>
                                    @endif
                                    <flux:sidebar.item :href="route('operations.expenses')"
                                        :current="request()->routeIs('operations.expenses')" wire:navigate>
                                        Expense Claims
                                    </flux:sidebar.item>
                                </flux:sidebar.group>
                                @break

                            @default
                                @php
                                    $miHref = \Illuminate\Support\Facades\Route::has($mi['route'] ?? '') ? route($mi['route']) : '#';
                                    $miCount = ($mi['badge'] ?? null) ? (int) ($menuBadges[$mi['badge']] ?? 0) : 0;
                                @endphp
                                {{-- Flux's own badge slot, so the count hides cleanly when the
                                     rail is collapsed; the explicit tooltip keeps the collapsed
                                     hover label plain text. --}}
                                <flux:sidebar.item :icon="$mi['icon']" :href="$miHref" :current="request()->routeIs($mi['active'])"
                                    :tooltip="$mi['label']"
                                    :badge="$miCount > 0 ? ($miCount > 9 ? '9+' : (string) $miCount) : null"
                                    :badge-color="($mi['badge'] ?? null) === 'inbox' ? 'red' : 'amber'" wire:navigate>
                                    {{ $mi['label'] }}
                                    @if($mi['key'] === 'overtime' && $showNexflow)
                                        <span
                                            class="ms-1 inline-flex items-center rounded-full bg-violet-100 px-1.5 py-0.5 text-[10px] font-bold text-violet-700 dark:bg-violet-500/20 dark:text-violet-300">Nexflow</span>
                                    @endif
                                </flux:sidebar.item>
                        @endswitch
                    @endforeach

                {{-- ════════════════════════════════════════════
                STAFF SIDEBAR — one menu for every non-self-service user, built
                from permissions. Groups with nothing the user can open are
                absent, so each role gets its own navigation.
                ════════════════════════════════════════════ --}}
                @else
                    @foreach($navGroups as $group)
                        @if($group['heading'] === null || count($group['items']) === 1)
                            @foreach($group['items'] as $item)
                                @include('layouts.app.partials.nav-item', ['item' => $item, 'icon' => $item['icon'] ?? $group['icon']])
                            @endforeach
                        @else
                            <flux:sidebar.group :heading="$group['heading']" :icon="$group['icon']" :expandable="true" :expanded="$group['expanded']">
                                @foreach($group['items'] as $item)
                                    @include('layouts.app.partials.nav-item', ['item' => $item, 'icon' => null])
                                @endforeach
                            </flux:sidebar.group>
                        @endif
                    @endforeach
                @endif

                {{-- AI Assistant for employees (staff get it in their menu above) --}}
                @if($pureEmployee && app(\App\Services\AiAssistant::class)->enabledForUser($user))
                    <flux:sidebar.item icon="sparkles" :href="route('ai.assistant')" :current="request()->routeIs('ai.assistant')" wire:navigate>
                        AI Assistant
                    </flux:sidebar.item>
                @endif
            @endauth
        </flux:sidebar.nav>

        <flux:spacer />

        @if($navSettings)
            <flux:sidebar.nav class="px-2 pb-1">
                <flux:sidebar.group :heading="$navSettings['heading']" :icon="$navSettings['icon']" :expandable="true"
                    :expanded="$navSettings['expanded']">
                    @foreach($navSettings['items'] as $item)
                        @include('layouts.app.partials.nav-item', ['item' => $item, 'icon' => null])
                    @endforeach
                </flux:sidebar.group>
            </flux:sidebar.nav>
        @endif

        {{-- Meet Pulse AI promo. Not for employees: their nav already carries a
             plain "AI Assistant" item (shown only when AI is enabled for them),
             so the promo just crowded out the HR tasks. --}}
        @if(Route::has('ai.assistant') && ! $pureEmployee && app(\App\Services\AiAssistant::class)->enabledForUser($user))
            <div class="px-3 pb-1 pt-1 in-data-flux-sidebar-collapsed-desktop:hidden">
                <div class="overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 to-orange-400 p-4 text-white shadow-lg shadow-orange-500/20">
                    <div class="flex items-center gap-2 text-[13px] font-bold">
                        <flux:icon.sparkles class="size-4" /> Meet Pulse AI
                    </div>
                    <p class="mt-1 text-[11px] leading-snug text-white/85">Get instant insights and automate HR tasks.</p>
                    <a href="{{ route('ai.assistant') }}" wire:navigate
                        class="mt-3 inline-flex w-full items-center justify-center gap-1.5 rounded-xl bg-white/95 px-3 py-2 text-xs font-bold text-orange-600 transition hover:bg-white">
                        <flux:icon.cpu-chip class="size-4" /> Ask AI Assistant
                    </a>
                </div>
            </div>
        @endif

        {{-- User profile. Employees see their job title rather than the role
             name (the header beside their name already says "Employee"). --}}
        @php
            $miniProfileSub = $pureEmployee ? ($employee?->jobTitle?->name ?? $roleLabel) : $roleLabel;
            $miniProfilePhoto = $employee?->photo ? \Illuminate\Support\Facades\Storage::url($employee->photo) : null;
        @endphp
        <div data-pulse-sidebar-account class="mt-1 border-t border-[#F3E8DD] px-3 pb-3 pt-3 in-data-flux-sidebar-collapsed-desktop:px-0">
            <flux:dropdown position="top" align="start" class="w-full">
                <button type="button" aria-label="{{ auth()->user()->name }} — account menu"
                    class="flex w-full items-center gap-3 rounded-2xl border border-[#F3E8DD] bg-white p-2.5 text-left shadow-sm transition hover:bg-[#FFF2E8] focus-visible:outline-2 focus-visible:outline-orange-500 in-data-flux-sidebar-collapsed-desktop:justify-center in-data-flux-sidebar-collapsed-desktop:border-0 in-data-flux-sidebar-collapsed-desktop:bg-transparent in-data-flux-sidebar-collapsed-desktop:p-0 in-data-flux-sidebar-collapsed-desktop:shadow-none">
                    <div class="relative shrink-0">
                        @if($miniProfilePhoto)
                            <img src="{{ $miniProfilePhoto }}" alt="" class="size-9 rounded-xl object-cover">
                        @else
                            <div class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-orange-500 to-orange-400 text-[13px] font-bold text-white">
                                {{ auth()->user()->initials() }}
                            </div>
                        @endif
                        <span class="absolute -bottom-0.5 -right-0.5 size-3 rounded-full border-2 border-white bg-emerald-500"
                            title="Online"></span>
                    </div>
                    <div class="min-w-0 flex-1 in-data-flux-sidebar-collapsed-desktop:hidden">
                        <div class="truncate text-[13px] font-bold text-[#111827]">{{ auth()->user()->name }}</div>
                        <div class="truncate text-[11px] font-semibold" style="color: {{ $roleColor }}">{{ $miniProfileSub }}</div>
                    </div>
                    <flux:icon.chevron-up-down class="size-4 shrink-0 text-[#9CA3AF] in-data-flux-sidebar-collapsed-desktop:hidden" />
                </button>
                <flux:menu class="w-56">
                    @include('layouts.app.partials.account-menu')
                </flux:menu>
            </flux:dropdown>
        </div>

    </flux:sidebar>

    {{-- TOP HEADER — compact, sticky, glassy, role-aware accent --}}
    <flux:header
        style="--color-accent: {{ $roleColor }}; --color-accent-content: {{ $roleColor }};"
        class="sticky top-0 z-30 border-b border-zinc-200/70 bg-white/80 backdrop-blur-xl dark:border-zinc-800/70 dark:bg-zinc-950/70 {{ session('impersonator_id') ? 'max-sm:flex-wrap max-sm:pb-2' : '' }}">
        <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

        {{-- Search --}}
        <div class="ms-2 hidden w-full max-w-sm flex-1 items-center gap-2 lg:flex" x-data>
            <flux:input placeholder="{{ __('Search anything...') }}" icon="magnifying-glass" size="sm"
                class="w-full cursor-pointer" kbd="⌘ K" readonly @click="$flux.modal('global-search').show()" />
        </div>

        {{-- "View as" impersonation. Lives in the sticky header so it is always
             on screen without covering page content (it used to float over the
             bottom of the page). On a phone the header wraps and this becomes a
             full-width second row, so the name is never squeezed out. --}}
        @if(session('impersonator_id'))
            <div role="status"
                class="ms-2 flex min-w-0 items-center gap-2 rounded-full border border-amber-300 bg-amber-50 py-1 pe-1 ps-2.5 text-amber-900 max-sm:order-last max-sm:ms-0 max-sm:basis-full dark:border-amber-500/40 dark:bg-amber-500/10 dark:text-amber-200">
                <flux:icon.eye class="size-4 shrink-0" />
                <span class="min-w-0 flex-1 truncate text-xs font-semibold">
                    Viewing as {{ auth()->user()->name }}
                </span>
                <a href="{{ route('impersonate.stop') }}"
                    class="shrink-0 rounded-full bg-amber-500 px-2.5 py-1 text-xs font-semibold text-white transition hover:bg-amber-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-500">
                    Exit to Admin
                </a>
            </div>
        @endif

        <flux:spacer />

        <div class="flex items-center gap-0.5">
            {{-- Mobile search --}}
            <flux:tooltip :content="__('Search')" position="bottom">
                <flux:button x-data icon="magnifying-glass" variant="subtle" size="sm" square
                    class="lg:hidden" @click="$flux.modal('global-search').show()" aria-label="{{ __('Search') }}" />
            </flux:tooltip>

            {{-- Dark / light toggle — single icon, no text --}}
            <flux:tooltip :content="__('Toggle theme')" position="bottom">
                <flux:button x-data x-on:click="$flux.dark = ! $flux.dark"
                    variant="subtle" size="sm" square aria-label="{{ __('Toggle theme') }}">
                    <flux:icon.moon x-show="!$flux.dark" x-cloak class="size-5" />
                    <flux:icon.sun x-show="$flux.dark" x-cloak class="size-5" />
                </flux:button>
            </flux:tooltip>

            {{-- Notifications --}}
            <livewire:notifications />

            {{-- Divider --}}
            <div class="mx-1.5 h-6 w-px bg-zinc-200 dark:bg-zinc-800"></div>

            {{-- Profile --}}
            <flux:dropdown position="bottom" align="end">
                <button type="button"
                    class="flex items-center gap-2.5 rounded-xl py-1 pe-2 ps-1 transition hover:bg-zinc-100 dark:hover:bg-zinc-800/60">
                    <flux:avatar :initials="auth()->user()->initials()" size="sm"
                        style="background-color: {{ $roleColor }}" class="text-white" />
                    <div class="hidden text-start leading-tight sm:block">
                        <div class="max-w-[120px] truncate text-[13px] font-bold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</div>
                        <div class="text-[10px] font-semibold uppercase tracking-wide" style="color: {{ $roleColor }}">{{ $roleLabel }}</div>
                    </div>
                    <flux:icon.chevron-down class="size-4 text-zinc-400" />
                </button>
                <flux:menu class="w-56">
                    @include('layouts.app.partials.account-menu')
                </flux:menu>
            </flux:dropdown>
        </div>
    </flux:header>

    {{ $slot }}

    <flux:modal name="global-search" class="w-full max-w-2xl">
        <div x-data="{ q: '' }" x-on:keydown.escape.window="$flux.modal('global-search').close()" class="space-y-5">
            <div>
                <flux:heading size="lg">Search Anything</flux:heading>
                <flux:subheading>Jump quickly to the most-used pages in Pulse.</flux:subheading>
            </div>

            <div class="rounded-2xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-3">
                    <flux:icon.magnifying-glass class="size-4 text-zinc-400" />
                    <input x-model="q" type="text" placeholder="Search dashboard, attendance, leave, payroll..."
                        class="w-full border-0 bg-transparent p-0 text-sm text-zinc-900 outline-none placeholder:text-zinc-400 focus:ring-0 dark:text-white">
                </div>
            </div>

            <div class="max-h-[420px] space-y-2 overflow-y-auto">
                @foreach($searchLinks as $item)
                    <a href="{{ $item['route'] }}" wire:navigate
                        x-show="'{{ \Illuminate\Support\Str::lower($item['label'] . ' ' . $item['caption']) }}'.includes(q.toLowerCase())"
                        class="block rounded-2xl border border-zinc-200 bg-white px-4 py-3 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-950 dark:hover:border-zinc-700 dark:hover:bg-zinc-900">
                        <div class="flex items-center justify-between gap-3">
                            <div>
                                <div class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $item['label'] }}</div>
                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['caption'] }}</div>
                            </div>
                            <flux:icon.arrow-up-right class="size-4 text-zinc-300 dark:text-zinc-600" />
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </flux:modal>

    <flux:toast />

    {{-- AI HR Copilot — renders nothing unless OPENAI_API_KEY is configured --}}
    @auth
        <livewire:ai-copilot />
    @endauth

    @fluxScripts
</body>

</html>