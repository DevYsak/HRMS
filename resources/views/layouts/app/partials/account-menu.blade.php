{{-- One account menu, shared by the sidebar profile block and the header, so
     the two can never drift apart. Every link is one the user can open. --}}
@php
    $menuUser = auth()->user();
    $menuRouteAccess = app(\App\Services\Help\RouteAccess::class);
@endphp
<div class="flex items-center gap-3 px-2 py-2">
    <flux:avatar :initials="$menuUser->initials()" size="sm" class="bg-brand-600 text-white" />
    <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ $menuUser->name }}</p>
        <p class="truncate text-xs text-zinc-500">{{ $menuUser->email }}</p>
    </div>
</div>
<flux:menu.separator />
@if($menuUser->employee)
    <flux:menu.item :href="route('profile.me')" icon="user" wire:navigate>My Profile</flux:menu.item>
@endif
<flux:menu.item :href="route('profile.edit')" icon="cog-6-tooth" wire:navigate>Account settings</flux:menu.item>
<flux:menu.item :href="route('settings.preferences')" icon="adjustments-horizontal" wire:navigate>Preferences</flux:menu.item>
<flux:menu.item :href="route('appearance.edit')" icon="paint-brush" wire:navigate>Appearance</flux:menu.item>
<flux:menu.item :href="route('help.getting-started')" icon="academic-cap" wire:navigate>Getting started</flux:menu.item>
<flux:menu.item :href="route('help.employee-guide')" icon="lifebuoy" wire:navigate>Help &amp; Guide</flux:menu.item>
@if($menuRouteAccess->allows($menuUser, 'settings.general'))
    <flux:menu.item :href="route('settings.general')" icon="wrench-screwdriver" wire:navigate>Company settings</flux:menu.item>
@endif
<flux:menu.separator />
<form method="POST" action="{{ route('logout') }}">
    @csrf
    <flux:menu.item as="button" type="submit" icon="arrow-right-start-on-rectangle"
        class="w-full text-red-600 dark:text-red-400">Log out</flux:menu.item>
</form>
