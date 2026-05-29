<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-5">

    {{-- â"€â"€â"€ HEADER â"€â"€â"€ --}}
    <div class="relative overflow-hidden rounded-2xl bg-gradient-to-br from-orange-500 via-orange-600 to-amber-600 p-7 shadow-xl shadow-orange-500/20">
        <div class="pointer-events-none absolute -top-16 -right-16 size-56 rounded-full bg-white/10 blur-3xl"></div>
        <div class="pointer-events-none absolute inset-0 opacity-[0.06]" style="background-image: radial-gradient(circle, white 1px, transparent 1px); background-size: 22px 22px;"></div>

        <div class="relative flex flex-col sm:flex-row sm:items-center justify-between gap-5">
            <div>
                <div class="mb-3 inline-flex items-center gap-2 rounded-full border border-white/20 bg-white/10 px-3 py-1 backdrop-blur-sm">
                    <div class="size-1.5 rounded-full bg-orange-200"></div>
                    <span class="text-[11px] font-bold uppercase tracking-[0.18em] text-orange-100">Operations</span>
                </div>
                <h1 class="text-3xl font-black tracking-tight text-white">Expense Claims</h1>
                <p class="mt-1.5 text-sm font-medium text-orange-200/80">Submit and manage employee expense reimbursements.</p>
            </div>
            <div class="flex shrink-0 flex-wrap items-center gap-3">
                <flux:button variant="ghost" icon="arrow-down-tray"
                    class="border-white/25 bg-white/15 text-white backdrop-blur-sm hover:bg-white/25">
                    Export
                </flux:button>
                @if($hasEmployeeProfile)
                    <button wire:click="openSubmitModal"
                        class="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-white px-5 py-2.5 text-sm font-black text-orange-700 shadow-lg shadow-black/20 transition-all hover:bg-orange-50">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        New Claim
                    </button>
                @endif
            </div>
        </div>
    </div>

    {{-- No Employee Profile Warning --}}
    @if(!$hasEmployeeProfile)
        <div class="flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-5 py-4 dark:border-amber-800/50 dark:bg-amber-950/20">
            <svg class="mt-0.5 size-5 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <div>
                <p class="font-bold text-amber-800 dark:text-amber-300">No active employee profile</p>
                <p class="mt-0.5 text-sm text-amber-700 dark:text-amber-400">You cannot submit expense claims until HR links your account to an employee profile. Please contact your HR administrator.</p>
            </div>
        </div>
    @endif

    {{-- â"€â"€â"€ FILTERS â"€â"€â"€ --}}
    <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl px-5 py-4">
        <div class="flex flex-wrap items-center gap-3">
            <flux:input wire:model.live.debounce.300ms="search" placeholder="Search title..." icon="magnifying-glass" size="sm" class="w-52" />
            <flux:select wire:model.live="filterStatus" placeholder="All Status" size="sm" class="w-36">
                <option value="">All Status</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="rejected">Rejected</option>
            </flux:select>
            <flux:select wire:model.live="filterCategory" placeholder="All Categories" size="sm" class="w-40">
                <option value="">All Categories</option>
                <option value="Travel">Travel</option>
                <option value="Food">Food</option>
                <option value="Equipment">Equipment</option>
                <option value="Medical">Medical</option>
                <option value="Training">Training</option>
                <option value="general">General</option>
            </flux:select>
            <flux:input wire:model.live="filterMonth" type="month" size="sm" class="w-44" />
            @if($filterStatus || $filterMonth || $filterCategory || $search)
                <button wire:click="clearFilters"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-700 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 rounded-lg transition">
                    <flux:icon.x-mark class="size-3.5" /> Clear
                </button>
            @endif
            <div class="ml-auto text-xs text-zinc-400">{{ $expenses->total() }} records</div>
        </div>
    </div>

    {{-- â"€â"€â"€ TABLE â"€â"€â"€ --}}
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
                                    <div class="flex size-7 items-center justify-center rounded-full bg-brand-600 text-[10px] font-black text-white">
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
        @if($expenses->hasPages())
            <div class="border-t border-zinc-50 px-6 py-3 dark:border-zinc-800">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>

    {{-- ─── REJECT MODAL ─── --}}
    @if($showRejectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showRejectModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showRejectModal', false)"></div>
            <div class="relative w-full max-w-md bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">
                <button type="button" @click="$wire.set('showRejectModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div class="flex items-start gap-3">
                    <div class="shrink-0 rounded-xl bg-rose-50 p-2.5 dark:bg-rose-900/20">
                        <flux:icon.x-circle class="size-5 text-rose-600 dark:text-rose-400" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">Reject Expense Claim</h2>
                        <p class="text-sm text-zinc-500 mt-0.5">Provide a reason so the employee can resubmit.</p>
                    </div>
                </div>
                <div>
                    <label class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Reason for Rejection</label>
                    <textarea wire:model="rejectionReason" rows="3"
                        placeholder="e.g. Missing receipt, amount exceeds policy limit..."
                        class="mt-1 w-full rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 px-3 py-2 text-sm text-zinc-700 dark:text-zinc-300 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 resize-none"></textarea>
                </div>
                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-700">
                    <button type="button" @click="$wire.set('showRejectModal', false)"
                        class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 transition-colors">
                        Cancel
                    </button>
                    <button type="button" wire:click="reject"
                        class="px-4 py-2 text-sm font-semibold text-white bg-red-500 hover:bg-red-600 rounded-xl transition-colors">
                        Confirm Rejection
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ─── SUBMIT MODAL ─── --}}
    @if($showSubmitModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.set('showSubmitModal', false)">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.set('showSubmitModal', false)"></div>
            <div class="relative w-full max-w-xl bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 space-y-5">
                <button type="button" @click="$wire.set('showSubmitModal', false)"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
                <div class="flex items-start gap-3">
                    <div class="shrink-0 rounded-xl bg-orange-50 p-2.5 dark:bg-orange-900/20">
                        <flux:icon.receipt-percent class="size-5 text-orange-500 dark:text-orange-400" />
                    </div>
                    <div>
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">Submit Expense Claim</h2>
                        <p class="text-sm text-zinc-500 mt-0.5">Upload your receipt and fill in the expense details.</p>
                    </div>
                </div>

                <form wire:submit="submit" class="space-y-4">
                    <flux:field>
                        <flux:input wire:model="title" label="Title" placeholder="e.g. Client dinner, Flight to Mumbai" required />
                        @error('title') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:select wire:model="category" label="Category">
                                <option value="travel">Travel</option>
                                <option value="meals">Meals & Entertainment</option>
                                <option value="accommodation">Accommodation</option>
                                <option value="equipment">Equipment</option>
                                <option value="software">Software / Subscriptions</option>
                                <option value="training">Training</option>
                                <option value="general">General</option>
                            </flux:select>
                            @error('category') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </flux:field>
                        <flux:field>
                            <flux:input wire:model="amount" type="number" step="0.01" min="1" label="Amount (₹)" required />
                            @error('amount') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:input wire:model="expenseDate" type="date" label="Expense Date"
                            max="{{ now()->toDateString() }}" required />
                        @error('expenseDate') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </flux:field>

                    <flux:field>
                        <flux:label>Receipt <span class="text-zinc-400 font-normal">(PDF, JPG, PNG — max 5MB)</span></flux:label>
                        <flux:input wire:model="receipt" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp" class="mt-1" />
                        @error('receipt') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </flux:field>

                    <flux:field>
                        <flux:textarea wire:model="notes" label="Notes (optional)" rows="2"
                            placeholder="Any additional details for the reviewer..." />
                        @error('notes') <p class="text-xs text-red-500 mt-1">{{ $message }}</p> @enderror
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-2 border-t border-zinc-100 dark:border-zinc-700">
                        <button type="button" @click="$wire.set('showSubmitModal', false)"
                            class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 bg-white dark:bg-zinc-700 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 transition-colors">
                            Cancel
                        </button>
                        <button type="submit"
                            wire:loading.attr="disabled" wire:target="submit"
                            class="inline-flex items-center gap-2 px-5 py-2 text-sm font-bold text-white bg-brand-600 hover:bg-brand-700 rounded-xl transition-colors disabled:opacity-60">
                            <flux:icon.paper-airplane class="size-4" />
                            <span wire:loading.remove wire:target="submit">Submit Claim</span>
                            <span wire:loading wire:target="submit">Submitting…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

</flux:main>
