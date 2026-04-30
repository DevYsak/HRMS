<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    {{-- ─── HEADER ─── --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-emerald-600 via-teal-600 to-cyan-700 p-7 shadow-xl shadow-emerald-500/20">
        <div class="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-5">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 backdrop-blur-sm">
                    <div class="size-1.5 rounded-full bg-emerald-200"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-emerald-100">Operations</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">Expense Claims</h1>
                <p class="mt-1.5 text-sm font-medium text-emerald-200/80">Submit and manage employee expense reimbursements.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <flux:button variant="ghost" icon="arrow-down-tray"
                    class="border-white/25 bg-white/15 text-white backdrop-blur-sm hover:bg-white/25">
                    Export
                </flux:button>
                <button wire:click="openSubmitModal"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-emerald-700 shadow-lg shadow-black/20 transition-all hover:bg-emerald-50">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    New Claim
                </button>
            </div>
        </div>
    </div>

    {{-- ─── TABLE ─── --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead>
                    <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/30">
                        <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Claim</th>
                        <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Employee</th>
                        <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Amount</th>
                        <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Date</th>
                        <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Status</th>
                        <th class="py-3 pr-6 text-right text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                    @forelse($expenses as $expense)
                        <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                            <td class="py-4 pl-6 pr-4">
                                <div class="font-bold text-zinc-900 dark:text-white">{{ $expense->title }}</div>
                                <div class="mt-0.5 text-[11px] capitalize text-zinc-400">{{ $expense->category }}</div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex size-7 items-center justify-center rounded-full bg-emerald-600 text-[10px] font-black text-white">
                                        {{ strtoupper(substr($expense->employee->user->name, 0, 1)) }}
                                    </div>
                                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $expense->employee->user->name }}</span>
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <span class="font-black tabular-nums text-zinc-900 dark:text-white">
                                    ₹{{ number_format($expense->amount, 2) }}
                                </span>
                            </td>
                            <td class="px-4 py-4 text-sm text-zinc-500 dark:text-zinc-400">
                                {{ $expense->expense_date->format('d M Y') }}
                            </td>
                            <td class="px-4 py-4">
                                @php
                                    $badge = match($expense->status->value) {
                                        'pending'  => 'bg-amber-50 text-amber-700 dark:bg-amber-900/20 dark:text-amber-400',
                                        'approved' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400',
                                        'paid'     => 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400',
                                        default    => 'bg-rose-50 text-rose-700 dark:bg-rose-900/20 dark:text-rose-400',
                                    };
                                    $label = match($expense->status->value) {
                                        'pending' => 'Pending', 'approved' => 'Approved',
                                        'paid' => 'Paid', default => 'Rejected',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2.5 py-1 text-[10px] font-black uppercase tracking-wider {{ $badge }}">
                                    {{ $label }}
                                </span>
                            </td>
                            <td class="py-4 pr-6 text-right">
                                @if($canReview && $expense->status->value === $pendingStatus)
                                    <div class="flex justify-end gap-2">
                                        <flux:button size="sm" variant="primary" wire:click="approve({{ $expense->id }})">Approve</flux:button>
                                        <flux:button size="sm" variant="ghost"
                                            class="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950/30"
                                            wire:click="openRejectModal({{ $expense->id }})">Reject</flux:button>
                                    </div>
                                @elseif($expense->status->value === 'rejected' && $expense->rejection_reason)
                                    <span class="block max-w-xs text-right text-xs italic text-rose-400">{{ $expense->rejection_reason }}</span>
                                @else
                                    <span class="text-xs text-zinc-300 dark:text-zinc-600">—</span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-16 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                        <flux:icon.receipt-percent class="size-7 text-zinc-300 dark:text-zinc-600" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-zinc-400">No expense claims found.</p>
                                        <p class="mt-0.5 text-xs text-zinc-300 dark:text-zinc-600">Click "New Claim" to submit your first expense.</p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- ─── REJECT MODAL ─── --}}
    <flux:modal wire:model="showRejectModal" class="max-w-md">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-rose-50 p-2.5 dark:bg-rose-900/20">
                    <flux:icon.x-circle class="size-5 text-rose-600 dark:text-rose-400" />
                </div>
                <div>
                    <flux:heading size="lg">Reject Expense Claim</flux:heading>
                    <flux:subheading>Provide a reason so the employee can resubmit.</flux:subheading>
                </div>
            </div>
            <flux:textarea wire:model="rejectionReason" label="Reason for Rejection" rows="3"
                placeholder="e.g. Missing receipt, amount exceeds policy limit..." />
            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showRejectModal', false)">Cancel</flux:button>
                <flux:button variant="ghost"
                    class="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950/30"
                    wire:click="reject">Confirm Rejection</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- ─── SUBMIT MODAL ─── --}}
    <flux:modal wire:model="showSubmitModal" class="max-w-xl">
        <div class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-emerald-50 p-2.5 dark:bg-emerald-900/20">
                    <flux:icon.receipt-percent class="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div>
                    <flux:heading size="lg">Submit Expense Claim</flux:heading>
                    <flux:subheading>Upload your receipt and fill in the expense details.</flux:subheading>
                </div>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="title" label="Title" placeholder="e.g. Client dinner, Flight to Mumbai" required />

                <div class="grid grid-cols-2 gap-4">
                    <flux:select wire:model="category" label="Category">
                        <option value="travel">Travel</option>
                        <option value="meals">Meals & Entertainment</option>
                        <option value="accommodation">Accommodation</option>
                        <option value="equipment">Equipment</option>
                        <option value="software">Software / Subscriptions</option>
                        <option value="training">Training</option>
                        <option value="general">General</option>
                    </flux:select>
                    <flux:input wire:model="amount" type="number" step="0.01" min="1" label="Amount (₹)" required />
                </div>

                <flux:input wire:model="expenseDate" type="date" label="Expense Date"
                    max="{{ now()->toDateString() }}" required />

                <flux:field>
                    <flux:label>Receipt <span class="text-zinc-400 font-normal">(PDF, JPG, PNG — max 5MB)</span></flux:label>
                    <flux:input wire:model="receipt" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1" />
                    @error('receipt') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:textarea wire:model="notes" label="Notes (optional)" rows="2"
                    placeholder="Any additional details for the reviewer..." />
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button variant="primary" wire:click="submit" icon="paper-airplane">Submit Claim</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
