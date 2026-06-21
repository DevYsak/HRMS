<flux:main class="bg-zinc-50 dark:bg-zinc-950 p-4 md:p-8">
    <div class="max-w-7xl mx-auto">
        {{-- Settings Global Header --}}
        <div class="mb-8">
            <flux:heading size="xl" level="1">{{ __('Settings') }}</flux:heading>
            <flux:subheading size="lg">{{ __('Manage your profile, team, and account settings') }}</flux:subheading>
            <flux:separator variant="subtle" class="mt-4" />
        </div>

        <div class="flex flex-col md:flex-row gap-8">
            {{-- Sidebar Navigation --}}
            <div class="w-full md:w-64 shrink-0">
                <flux:navlist aria-label="{{ __('Settings') }}" class="space-y-1">
                    @can('manageFullSettings')
                        <flux:navlist.item :href="route('settings.general')" :current="request()->routeIs('settings.general')" wire:navigate icon="building-office-2">{{ __('Company') }}</flux:navlist.item>
                    @endcan
                    @can('manage_roles')
                        <flux:navlist.item :href="route('settings.roles')" :current="request()->routeIs('settings.roles')" wire:navigate icon="shield-check">{{ __('Roles & Permissions') }}</flux:navlist.item>
                    @endcan
                    @if(auth()->user()?->isSuperAdmin())
                        <flux:navlist.item :href="route('settings.ai')" :current="request()->routeIs('settings.ai')" wire:navigate icon="sparkles">{{ __('AI Assistant') }}</flux:navlist.item>
                    @endif
                    <flux:navlist.item :href="route('profile.edit')" :current="request()->routeIs('profile.edit')" wire:navigate icon="user">{{ __('Profile') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('security.edit')" :current="request()->routeIs('security.edit')" wire:navigate icon="lock-closed">{{ __('Security') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('teams.index')" :current="request()->routeIs('teams.*')" wire:navigate icon="users">{{ __('Teams') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('settings.holidays')" :current="request()->routeIs('settings.holidays')" wire:navigate icon="calendar-days">{{ __('Holidays') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('appearance.edit')" :current="request()->routeIs('appearance.edit')" wire:navigate icon="paint-brush">{{ __('Appearance') }}</flux:navlist.item>
                    <flux:navlist.item :href="route('settings.preferences')" :current="request()->routeIs('settings.preferences')" wire:navigate icon="adjustments-horizontal">{{ __('Preferences') }}</flux:navlist.item>
                    @can('manageFullSettings')
                        <flux:separator class="my-2" />
                        <flux:navlist.item :href="route('settings.employment-types')" :current="request()->routeIs('settings.employment-types')" wire:navigate icon="briefcase">{{ __('Employment Types') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('settings.work-modes')" :current="request()->routeIs('settings.work-modes')" wire:navigate icon="computer-desktop">{{ __('Work Modes') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('settings.salary-cycles')" :current="request()->routeIs('settings.salary-cycles')" wire:navigate icon="arrow-path">{{ __('Salary Cycles') }}</flux:navlist.item>
                        <flux:navlist.item :href="route('settings.job-titles')" :current="request()->routeIs('settings.job-titles')" wire:navigate icon="identification">{{ __('Job Titles') }}</flux:navlist.item>
                    @endcan
                </flux:navlist>
            </div>

            {{-- Main Content Window --}}
            <div class="flex-1 min-w-0">
                <flux:card class="p-6 md:p-8 bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 shadow-sm rounded-xl">
                    <div class="mb-6 pb-6 border-b border-zinc-100 dark:border-zinc-800">
                        <flux:heading size="lg">{{ $heading ?? '' }}</flux:heading>
                        @if(isset($subheading) && $subheading)
                            <flux:subheading class="mt-1">{{ $subheading }}</flux:subheading>
                        @endif
                    </div>

                    <div class="{{ request()->routeIs('settings.roles') ? 'w-full' : 'w-full max-w-2xl' }}">
                        {{ $slot }}
                    </div>
                </flux:card>
            </div>
        </div>
    </div>
</flux:main>
