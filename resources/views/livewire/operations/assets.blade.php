<flux:main>
        <div class="flex items-center justify-between mb-8">
            <div>
                <flux:heading size="xl" level="1">{{ __('Company Assets') }}</flux:heading>
                <flux:subheading>{{ __('Manage hardware, equipment, and other company assets assigned to employees.') }}</flux:subheading>
            </div>
            <div class="flex items-center gap-2">
                <flux:button icon="plus" variant="primary">{{ __('Add Asset') }}</flux:button>
            </div>
        </div>

        <flux:card class="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400">
                        <tr>
                            <th class="px-6 py-4 font-medium">{{ __('Asset Name') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Type') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Assigned To') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Status') }}</th>
                            <th class="px-6 py-4 font-medium text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($assets as $asset)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $asset->name }}</div>
                                    <div class="text-xs text-zinc-500">SN: {{ $asset->serial_number ?? 'N/A' }}</div>
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400 capitalize">{{ $asset->type }}</td>
                                <td class="px-6 py-4">
                                    @if($asset->employee)
                                        <div class="flex items-center gap-2">
                                            <flux:avatar size="xs" :initials="strtoupper(substr($asset->employee->user->name, 0, 1))" class="bg-brand-600 text-white" />
                                            <span>{{ $asset->employee->user->name }}</span>
                                        </div>
                                    @else
                                        <span class="text-zinc-400 italic">Unassigned</span>
                                    @endif
                                </td>
                                <td class="px-6 py-4">
                                    @if($asset->status->value === 'available')
                                        <flux:badge color="green" size="sm">Available</flux:badge>
                                    @elseif($asset->status->value === 'assigned')
                                        <flux:badge color="blue" size="sm">Assigned</flux:badge>
                                    @elseif($asset->status->value === 'maintenance')
                                        <flux:badge color="orange" size="sm">Maintenance</flux:badge>
                                    @else
                                        <flux:badge color="red" size="sm">Lost/Broken</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-12 text-center text-zinc-500">
                                    <flux:icon.computer-desktop class="size-8 mx-auto mb-3 text-zinc-400" />
                                    <p>{{ __('No assets found in the system.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>
    </flux:main>
