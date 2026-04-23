<flux:main>
        <div class="flex items-center justify-between mb-8">
            <div>
                <flux:heading size="xl" level="1">{{ __('Expense Claims') }}</flux:heading>
                <flux:subheading>{{ __('Review and manage employee expense reimbursements.') }}</flux:subheading>
            </div>
            <div class="flex items-center gap-2">
                <flux:button icon="arrow-down-tray" variant="outline">{{ __('Export') }}</flux:button>
                <flux:button icon="plus" variant="primary">{{ __('New Claim') }}</flux:button>
            </div>
        </div>

        <flux:card class="p-0">
            <div class="overflow-x-auto">
                <table class="w-full text-sm text-left">
                    <thead class="text-xs uppercase bg-zinc-50 dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-800 text-zinc-500 dark:text-zinc-400">
                        <tr>
                            <th class="px-6 py-4 font-medium">{{ __('Claim') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Employee') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Amount') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Date') }}</th>
                            <th class="px-6 py-4 font-medium">{{ __('Status') }}</th>
                            <th class="px-6 py-4 font-medium text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @forelse($expenses as $expense)
                            <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-900/50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $expense->title }}</div>
                                    <div class="text-xs text-zinc-500 capitalize">{{ $expense->category }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <div class="flex items-center gap-2">
                                        <flux:avatar size="xs" :initials="strtoupper(substr($expense->employee->user->name, 0, 1))" class="bg-brand-600 text-white" />
                                        <span>{{ $expense->employee->user->name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 font-mono font-medium text-zinc-900 dark:text-white">
                                    ${{ number_format($expense->amount, 2) }}
                                </td>
                                <td class="px-6 py-4 text-zinc-600 dark:text-zinc-400">
                                    {{ $expense->expense_date->format('M d, Y') }}
                                </td>
                                <td class="px-6 py-4">
                                    @if($expense->status->value === 'pending')
                                        <flux:badge color="orange" size="sm">Pending</flux:badge>
                                    @elseif($expense->status->value === 'approved')
                                        <flux:badge color="green" size="sm">Approved</flux:badge>
                                    @elseif($expense->status->value === 'paid')
                                        <flux:badge color="zinc" size="sm">Paid</flux:badge>
                                    @else
                                        <flux:badge color="red" size="sm">Rejected</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-12 text-center text-zinc-500">
                                    <flux:icon.receipt-percent class="size-8 mx-auto mb-3 text-zinc-400" />
                                    <p>{{ __('No expense claims found.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </flux:card>
    </flux:main>
