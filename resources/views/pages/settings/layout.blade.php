@php
    /*
     * One permission-filtered list drives both the desktop side nav and the
     * mobile menu. Every link is checked with RouteAccess (the route's own
     * can:/role:/module: middleware), so the nav never offers a page that
     * answers 403. This is display only — routes and components still guard.
     */
    $settingsUser = auth()->user();
    $routeAccess = app(\App\Services\Help\RouteAccess::class);
    $canManageSettings = $settingsUser?->can('manageFullSettings') ?? false;

    $settingsGroups = [
        __('Organisation') => [
            ['route' => 'settings.general', 'label' => __('Company'), 'icon' => 'building-office-2', 'active' => 'settings.general', 'when' => $canManageSettings],
            ['route' => 'settings.ui-theme', 'label' => __('Brand & Layout'), 'icon' => 'swatch', 'active' => 'settings.ui-theme', 'when' => $canManageSettings],
            ['route' => 'settings.roles', 'label' => __('Roles & Permissions'), 'icon' => 'shield-check', 'active' => 'settings.roles', 'when' => $settingsUser?->can('manage_roles') ?? false],
            ['route' => 'settings.ai', 'label' => __('AI Assistant'), 'icon' => 'sparkles', 'active' => 'settings.ai', 'when' => $settingsUser?->hasPermission('manage_ai_settings') ?? false],
            ['route' => 'settings.holidays', 'label' => __('Holidays'), 'icon' => 'calendar-days', 'active' => 'settings.holidays', 'when' => $canManageSettings],
            // Starter-kit account teams, not HRMS teams (People → Teams): Super Admin only (Phase 4).
            ['route' => 'teams.index', 'label' => __('Account Teams (legacy)'), 'icon' => 'users', 'active' => 'teams.*', 'when' => $settingsUser?->can('manage-account-teams') ?? false],
        ],
        __('Account') => [
            ['route' => 'profile.edit', 'label' => __('Profile'), 'icon' => 'user', 'active' => 'profile.edit', 'when' => true],
            ['route' => 'security.edit', 'label' => __('Security'), 'icon' => 'lock-closed', 'active' => 'security.edit', 'when' => true],
            ['route' => 'appearance.edit', 'label' => __('Appearance'), 'icon' => 'paint-brush', 'active' => 'appearance.edit', 'when' => true],
            ['route' => 'settings.my-notifications', 'label' => __('My notifications'), 'icon' => 'bell', 'active' => 'settings.my-notifications', 'when' => true],
            ['route' => 'settings.preferences', 'label' => __('Preferences'), 'icon' => 'adjustments-horizontal', 'active' => 'settings.preferences', 'when' => true],
        ],
        __('Master data') => [
            ['route' => 'settings.employment-types', 'label' => __('Employment Types'), 'icon' => 'briefcase', 'active' => 'settings.employment-types', 'when' => $canManageSettings],
            ['route' => 'settings.work-modes', 'label' => __('Work Modes'), 'icon' => 'computer-desktop', 'active' => 'settings.work-modes', 'when' => $canManageSettings],
            ['route' => 'settings.salary-cycles', 'label' => __('Salary Cycles'), 'icon' => 'arrow-path', 'active' => 'settings.salary-cycles', 'when' => $canManageSettings && app(\App\Services\ModuleFeatureService::class)->payrollEnabled()],
            ['route' => 'settings.job-titles', 'label' => __('Job Titles'), 'icon' => 'identification', 'active' => 'settings.job-titles', 'when' => $canManageSettings],
        ],
    ];

    $settingsGroups = collect($settingsGroups)
        ->map(fn (array $items) => collect($items)
            ->filter(fn (array $item) => $settingsUser && $item['when'] && \Illuminate\Support\Facades\Route::has($item['route']) && $routeAccess->allows($settingsUser, $item['route']))
            ->map(fn (array $item) => $item + ['href' => route($item['route']), 'current' => request()->routeIs($item['active'])])
            ->values())
        ->filter(fn ($items) => $items->isNotEmpty());

    $currentSettingsItem = $settingsGroups->flatten(1)->firstWhere('current', true);
@endphp

<flux:main class="bg-zinc-50 p-4 md:p-8">
    <div class="mx-auto max-w-7xl">
        {{-- Settings Global Header --}}
        <div class="mb-6 md:mb-8">
            <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
            <flux:subheading size="lg">{{ __('Manage your profile, notifications and account preferences') }}</flux:subheading>
            <flux:separator variant="subtle" class="mt-4" />
        </div>

        <div class="flex flex-col gap-6 md:flex-row md:gap-8">
            {{-- Mobile: one compact menu instead of a long stacked list --}}
            <div class="md:hidden" data-settings-nav="mobile">
                <label for="settings-section" class="mb-1.5 block text-xs font-semibold uppercase tracking-wide text-zinc-500">{{ __('Settings section') }}</label>
                <select id="settings-section"
                    class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-800 shadow-sm focus:border-orange-400 focus:outline-none focus:ring-2 focus:ring-orange-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100"
                    x-data x-on:change="Livewire.navigate($event.target.value)">
                    @foreach($settingsGroups as $group => $items)
                        <optgroup label="{{ $group }}">
                            @foreach($items as $item)
                                <option value="{{ $item['href'] }}" @selected($item['current'])>{{ $item['label'] }}</option>
                            @endforeach
                        </optgroup>
                    @endforeach
                </select>
            </div>

            {{-- Desktop: grouped side nav --}}
            <nav class="hidden w-64 shrink-0 md:block" aria-label="{{ __('Settings') }}" data-settings-nav="desktop">
                <div class="sticky top-6 space-y-5">
                    @foreach($settingsGroups as $group => $items)
                        <div>
                            <p class="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-zinc-400">{{ $group }}</p>
                            <flux:navlist class="space-y-0.5">
                                @foreach($items as $item)
                                    <flux:navlist.item :href="$item['href']" :current="$item['current']" wire:navigate :icon="$item['icon']" data-settings-link="{{ $item['route'] }}">{{ $item['label'] }}</flux:navlist.item>
                                @endforeach
                            </flux:navlist>
                        </div>
                    @endforeach
                </div>
            </nav>

            {{-- Main Content Window --}}
            <div class="min-w-0 flex-1">
                <flux:card class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm sm:p-6 md:p-8 dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mb-6 border-b border-zinc-100 pb-6 dark:border-zinc-800">
                        <flux:heading size="lg">{{ $heading ?? ($currentSettingsItem['label'] ?? '') }}</flux:heading>
                        @if(isset($subheading) && $subheading)
                            <flux:subheading class="mt-1">{{ $subheading }}</flux:subheading>
                        @endif
                    </div>

                    <div class="{{ request()->routeIs('settings.roles', 'settings.my-notifications') ? 'w-full' : 'w-full max-w-2xl' }}">
                        {{ $slot }}
                    </div>
                </flux:card>
            </div>
        </div>
    </div>
</flux:main>
