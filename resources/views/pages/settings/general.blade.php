<x-layouts::app :title="__('General Settings')">
    <flux:main>
        <div class="pulse-page-header">
            <div>
                <h1 class="pulse-page-title">General Settings</h1>
                <p class="pulse-page-subtitle">Configure company settings</p>
            </div>
        </div>

        @can('manageFullSettings')
            <x-coming-soon title="General Settings" subtitle="Company details, offices, departments, and branding — coming in Phase 0 polish." icon="cog-6-tooth" />
        @else
            <div class="p-4 mb-4 text-sm text-red-800 rounded-lg bg-red-50 dark:bg-gray-800 dark:text-red-400" role="alert">
                <span class="font-medium">Access Denied!</span> You do not have permission to view or manage global settings.
            </div>
        @endcan
    </flux:main>
</x-layouts::app>
