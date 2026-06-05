<flux:main class="bg-[#F7F8FA] min-h-screen dark:bg-zinc-950">

    {{-- ── Page Header ─────────────────────────────────────────────────────── --}}
    <div class="bg-white dark:bg-zinc-900 border-b border-zinc-200/70 dark:border-zinc-800 px-8 py-5">
        <div class="flex items-center justify-between gap-4">
            <div>
                <div class="flex items-center gap-1.5 text-[11px] text-zinc-400 mb-1 font-medium">
                    <span>Time Off</span>
                    <flux:icon.chevron-right class="size-3" />
                    <span class="text-zinc-600 dark:text-zinc-300">Leave Encashments</span>
                </div>
                <h1 class="text-2xl font-black text-zinc-900 dark:text-white tracking-tight">Leave Encashment Approvals</h1>
                <p class="text-sm text-zinc-500 mt-0.5">Review, approve, and manage employee leave encashment requests</p>
            </div>
        </div>
    </div>

    <div class="p-8 space-y-6">

        {{-- ── KPI Cards ────────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-5">

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-amber-50 dark:bg-amber-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.clock class="size-6 text-amber-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Pending HR</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['pending'] }}</div>
                    <div class="text-xs text-amber-500 font-semibold mt-1">Awaiting Review</div>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-blue-50 dark:bg-blue-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.banknotes class="size-6 text-blue-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Pending Finance</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['pending_finance'] }}</div>
                    <div class="text-xs text-blue-500 font-semibold mt-1">Awaiting Finance</div>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-emerald-50 dark:bg-emerald-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.check-circle class="size-6 text-emerald-500" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Approved YTD</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['approved_ytd'] }}</div>
                    <div class="text-xs text-emerald-500 font-semibold mt-1">This Year</div>
                </div>
            </div>

            <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 p-5 shadow-sm flex items-center gap-4">
                <div class="size-12 rounded-xl bg-brand-50 dark:bg-brand-900/20 flex items-center justify-center shrink-0">
                    <flux:icon.currency-dollar class="size-6 text-brand-600" />
                </div>
                <div>
                    <div class="text-[11px] font-bold text-zinc-400 uppercase tracking-wider mb-0.5">Processed YTD</div>
                    <div class="text-3xl font-black text-zinc-900 dark:text-white leading-none">{{ $kpi['processed_ytd'] }}</div>
                    <div class="text-xs text-brand-600 font-semibold mt-1">In Payroll</div>
                </div>
            </div>

        </div>

        {{-- ── Table Card ───────────────────────────────────────────────────── --}}
        <div class="bg-white dark:bg-zinc-900 rounded-2xl border border-zinc-100 dark:border-zinc-800 shadow-sm overflow-hidden">

            {{-- Filter bar --}}
            <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex flex-wrap items-center gap-3">
                <div class="relative flex-1 min-w-[200px]">
                    <flux:icon.magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 size-4 text-zinc-400" />
                    <input
                        wire:model.live.debounce.300ms="search"
                        type="text"
                        placeholder="Search employee..."
                        class="w-full h-9 pl-9 pr-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-sm text-zinc-800 dark:text-zinc-200 placeholder-zinc-400 focus:outline-none focus:ring-2 focus:ring-brand-400/30 focus:border-brand-400 transition-colors"
                    />
                </div>
                <select wire:model.live="filterStatus"
                    class="h-9 px-3 rounded-xl border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 text-sm text-zinc-600 dark:text-zinc-300 focus:outline-none focus:ring-2 focus:ring-brand-400/30 transition-colors">
                    <option value="">All Statuses</option>
                    <option value="pending">Pending HR</option>
                    <option value="pending_finance">Pending Finance</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="processed">Processed</option>
                </select>
            </div>

            {{-- Table --}}
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Employee</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Leave Type</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Days</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Source Year</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Payout Month</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Rate ×</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Status</th>
                            <th class="px-6 py-3 text-left text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Submitted</th>
                            <th class="px-6 py-3 text-right text-[11px] font-bold text-zinc-400 uppercase tracking-wider">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800">
                        @forelse($encashments as $enc)
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/40 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $enc->employee->user->name ?? '—' }}</div>
                                    <div class="text-xs text-zinc-400">{{ $enc->employee->employee_id ?? '' }}</div>
                                </td>
                                <td class="px-6 py-4">
                                    <span class="inline-flex items-center gap-1.5">
                                        <span class="size-2 rounded-full shrink-0" style="background:{{ $enc->leaveType->color ?? '#94a3b8' }}"></span>
                                        <span class="text-zinc-700 dark:text-zinc-300">{{ $enc->leaveType->name ?? '—' }}</span>
                                    </span>
                                </td>
                                <td class="px-6 py-4 font-bold text-zinc-900 dark:text-white">{{ $enc->requested_days }}d</td>
                                <td class="px-6 py-4 text-zinc-500">{{ $enc->source_leave_year ?? '—' }}</td>
                                <td class="px-6 py-4 text-zinc-500">{{ $enc->payout_month ?? '—' }}</td>
                                <td class="px-6 py-4 text-zinc-500">{{ number_format($enc->leaveType->encashment_rate_multiplier ?? 1, 2) }}×</td>
                                <td class="px-6 py-4">
                                    @php
                                        $statusMap = [
                                            'pending'         => ['label' => 'Pending HR',      'class' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'],
                                            'pending_finance' => ['label' => 'Pending Finance',  'class' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'],
                                            'approved'        => ['label' => 'Approved',          'class' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'],
                                            'rejected'        => ['label' => 'Rejected',          'class' => 'bg-red-100 text-red-700 dark:bg-red-900/30 dark:text-red-400'],
                                            'processed'       => ['label' => 'Processed',         'class' => 'bg-brand-100 text-brand-700 dark:bg-brand-900/30 dark:text-brand-400'],
                                        ];
                                        $s = $statusMap[$enc->status] ?? ['label' => $enc->status, 'class' => 'bg-zinc-100 text-zinc-600'];
                                    @endphp
                                    <span class="inline-flex px-2.5 py-1 rounded-full text-xs font-semibold {{ $s['class'] }}">{{ $s['label'] }}</span>
                                </td>
                                <td class="px-6 py-4 text-zinc-400 text-xs">{{ $enc->created_at->format('d M Y') }}</td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end gap-2">
                                        @if($enc->status === 'pending' && auth()->user()->canApproveLeave())
                                            <button wire:click="openReview({{ $enc->id }}, 'approve')"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-emerald-700 bg-emerald-50 hover:bg-emerald-100 dark:bg-emerald-900/20 dark:text-emerald-400 dark:hover:bg-emerald-900/40 rounded-lg transition-colors">
                                                <flux:icon.check class="size-3.5" /> Approve
                                            </button>
                                            <button wire:click="openReview({{ $enc->id }}, 'reject')"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 rounded-lg transition-colors">
                                                <flux:icon.x-mark class="size-3.5" /> Reject
                                            </button>
                                        @elseif($enc->status === 'pending_finance' && auth()->user()->canApproveFinance())
                                            <button wire:click="openReview({{ $enc->id }}, 'approve')"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-blue-700 bg-blue-50 hover:bg-blue-100 dark:bg-blue-900/20 dark:text-blue-400 dark:hover:bg-blue-900/40 rounded-lg transition-colors">
                                                <flux:icon.check class="size-3.5" /> Finance Approve
                                            </button>
                                            <button wire:click="openReview({{ $enc->id }}, 'reject')"
                                                class="inline-flex items-center gap-1 px-3 py-1.5 text-xs font-semibold text-red-700 bg-red-50 hover:bg-red-100 dark:bg-red-900/20 dark:text-red-400 dark:hover:bg-red-900/40 rounded-lg transition-colors">
                                                <flux:icon.x-mark class="size-3.5" /> Reject
                                            </button>
                                        @else
                                            <span class="text-xs text-zinc-400">—</span>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-6 py-12 text-center">
                                    <flux:icon.banknotes class="size-10 text-zinc-300 dark:text-zinc-700 mx-auto mb-3" />
                                    <p class="text-sm text-zinc-400">No encashment requests found.</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if($encashments->hasPages())
                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800">
                    {{ $encashments->links() }}
                </div>
            @endif

        </div>

    </div>

    {{-- ── Review Modal ─────────────────────────────────────────────────────── --}}
    <flux:modal wire:model="showReviewModal" class="max-w-md w-full">
        @if($reviewingEncashment)
            <div class="p-6 space-y-5">
                <div>
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        {{ $reviewAction === 'approve' ? 'Approve Encashment' : 'Reject Encashment' }}
                    </h3>
                    <p class="text-sm text-zinc-500 mt-1">
                        {{ $reviewingEncashment->employee->user->name ?? '—' }} ·
                        {{ $reviewingEncashment->requested_days }} days of {{ $reviewingEncashment->leaveType->name ?? '—' }}
                    </p>
                </div>

                <div class="bg-zinc-50 dark:bg-zinc-800/60 rounded-xl p-4 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Payout month</span>
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $reviewingEncashment->payout_month ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Source year</span>
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $reviewingEncashment->source_leave_year ?? '—' }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-zinc-500">Rate multiplier</span>
                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ number_format($reviewingEncashment->leaveType->encashment_rate_multiplier ?? 1, 2) }}×</span>
                    </div>
                    @if($reviewingEncashment->reviewer)
                        <div class="flex justify-between">
                            <span class="text-zinc-500">HR reviewer</span>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $reviewingEncashment->reviewer->name }}</span>
                        </div>
                    @endif
                </div>

                <div>
                    <flux:textarea
                        wire:model="reviewComment"
                        label="{{ $reviewAction === 'reject' ? 'Rejection reason *' : 'Comment (optional)' }}"
                        rows="3"
                        placeholder="{{ $reviewAction === 'reject' ? 'State the reason for rejection...' : 'Any notes for the employee...' }}"
                    />
                    @error('reviewComment') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </div>

                <div class="flex justify-end gap-3 pt-1">
                    <flux:button wire:click="$set('showReviewModal', false)" variant="ghost">Cancel</flux:button>
                    <flux:button wire:click="submitReview"
                        variant="{{ $reviewAction === 'approve' ? 'primary' : 'danger' }}">
                        {{ $reviewAction === 'approve' ? 'Confirm Approval' : 'Confirm Rejection' }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>

</flux:main>
