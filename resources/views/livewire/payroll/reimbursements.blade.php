<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Reimbursements</h1>
            <p class="pulse-page-subtitle">Manage employee expense claims</p>
        </div>
        <flux:button @click="$flux.modal('reimbursement-modal').show()" variant="primary" icon="plus">
            Submit Claim
        </flux:button>
    </div>

    {{-- Filters --}}
    <div class="flex gap-3 flex-wrap">
        <flux:select wire:model.live="filterStatus" size="sm" placeholder="All Statuses">
            <flux:select.option value="">All Statuses</flux:select.option>
            <flux:select.option value="pending">Pending</flux:select.option>
            <flux:select.option value="approved">Approved</flux:select.option>
            <flux:select.option value="rejected">Rejected</flux:select.option>
            <flux:select.option value="included">Included in Payroll</flux:select.option>
        </flux:select>
        <flux:input wire:model.live="filterMonth" type="month" size="sm" />
    </div>

    {{-- Table --}}
    <div class="pulse-card">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Employee</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Title / Category</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Date</th>
                        <th class="pb-3 pr-4 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Amount</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</th>
                        <th class="pb-3 pr-6 text-right text-xs font-semibold uppercase tracking-wide text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                    @forelse($reimbursements as $rei)
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800/30">
                        <td class="py-4 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">{{ $rei->employee?->user?->name }}</td>
                        <td class="py-4 pr-4">
                            <div class="font-medium text-zinc-900 dark:text-white">{{ $rei->title }}</div>
                            <div class="text-[10px] uppercase text-zinc-400">{{ $rei->category }}</div>
                        </td>
                        <td class="py-4 pr-4 text-zinc-500">{{ $rei->expense_date?->format('d M Y') }}</td>
                        <td class="py-4 pr-4 text-right font-bold text-zinc-900 dark:text-white">₹{{ number_format($rei->amount, 0) }}</td>
                        <td class="py-4 pr-4">
                            <span class="text-[10px] font-bold px-2 py-1 rounded-full
                                {{ match($rei->status) {
                                    'approved' => 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-400',
                                    'rejected' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-400',
                                    'included' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-400',
                                    default    => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-400',
                                } }}">
                                {{ strtoupper($rei->status) }}
                            </span>
                        </td>
                        <td class="py-4 pr-6 text-right">
                            @if($rei->status === 'pending')
                            <div class="flex gap-2 justify-end">
                                <flux:button size="xs" variant="primary" wire:click="approve({{ $rei->id }})">Approve</flux:button>
                                <flux:button size="xs" variant="danger" wire:click="reject({{ $rei->id }})">Reject</flux:button>
                            </div>
                            @else
                            <span class="text-zinc-400 text-xs">{{ $rei->approvedBy?->name ?? '—' }}</span>
                            @endif
                        </td>
                    </tr>
                    @empty
                    <tr><td colspan="6" class="py-12 text-center text-zinc-400">No reimbursement claims found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">{{ $reimbursements->links() }}</div>
    </div>

    {{-- Add Modal --}}
    <flux:modal name="reimbursement-modal" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Submit Expense Claim</flux:heading>
                <flux:subheading>Attach receipt and submit for Finance approval</flux:subheading>
            </div>
            <div class="space-y-4">
                <flux:select wire:model="employeeId" label="Employee">
                    <flux:select.option value="">Select Employee</flux:select.option>
                    @foreach($employees as $emp)
                    <flux:select.option value="{{ $emp->id }}">{{ $emp->user?->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="title" label="Expense Title" placeholder="e.g. Client dinner, Taxi fare" />
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="amount" label="Amount (₹)" type="number" min="1" />
                    <flux:input wire:model="expenseDate" label="Expense Date" type="date" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model="category" label="Category">
                        <flux:select.option value="general">General</flux:select.option>
                        <flux:select.option value="travel">Travel</flux:select.option>
                        <flux:select.option value="food">Food</flux:select.option>
                        <flux:select.option value="equipment">Equipment</flux:select.option>
                    </flux:select>
                    <flux:input wire:model="month" label="Payout Month" type="month" />
                </div>
                <div>
                    <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Receipt (optional)</label>
                    <input type="file" wire:model="receipt" accept=".jpg,.jpeg,.png,.pdf" class="text-sm text-zinc-500 file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-sm file:font-medium file:bg-brand-50 file:text-brand-700 hover:file:bg-brand-100 dark:file:bg-brand-900/40 dark:file:text-brand-400" />
                </div>
                <flux:textarea wire:model="description" label="Notes (optional)" rows="2" />
            </div>
            <div class="flex gap-2 justify-end">
                <flux:button @click="$flux.modal('reimbursement-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submit" variant="primary">Submit Claim</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
