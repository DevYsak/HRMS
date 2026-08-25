<flux:main class="bg-zinc-50 dark:bg-zinc-950 min-h-screen">

    {{-- Header --}}
    {{-- Matches the shared hero used by Overtime, Payslips, Time Off and the
         dashboard. This page was the only one in the app rendered in blue,
         which read as a different product rather than a different section. --}}
    <div class="pulse-hero">
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(ellipse_at_bottom_left,_rgba(249,115,22,0.22),_transparent_65%)]"></div>
        <div class="pointer-events-none absolute -bottom-10 -left-10 size-64 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.30),transparent 70%)"></div>
        <div class="pointer-events-none absolute top-0 right-0 size-48 rounded-full blur-3xl" style="background:radial-gradient(circle,rgba(249,115,22,0.08),transparent 70%)"></div>
        <div class="relative flex flex-col md:flex-row md:items-center md:justify-between gap-4">
            <div>
                <h1 class="text-3xl font-black text-white tracking-tight">My Work From Home</h1>
                <p class="text-white/55 text-sm mt-1">Submit and track your work-from-home requests.</p>
            </div>
            <button wire:click="openModal"
                class="inline-flex items-center gap-2 px-5 py-2.5 bg-brand-600 hover:bg-brand-700 border border-orange-400/50 text-white rounded-xl text-sm font-bold transition shadow-lg shadow-orange-900/30">
                <flux:icon.plus class="size-4" /> Request WFH
            </button>
        </div>
    </div>

    <div class="p-4 md:p-6 space-y-5">

        {{-- Summary Cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Total Requests</div>
                <div class="text-3xl font-black text-zinc-900 dark:text-white">{{ $summary['total'] }}</div>
                <div class="text-xs text-zinc-400 mt-1">all time</div>
            </div>
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Approved</div>
                <div class="text-3xl font-black text-emerald-600">{{ $summary['approved'] }}</div>
                <div class="text-xs text-zinc-400 mt-1">approved requests</div>
            </div>
            <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl p-5">
                <div class="text-[10px] font-bold text-zinc-400 uppercase tracking-widest mb-2">Pending</div>
                <div class="text-3xl font-black text-amber-500">{{ $summary['pending'] }}</div>
                <div class="text-xs text-zinc-400 mt-1">awaiting approval</div>
            </div>
        </div>

        {{-- Filters --}}
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl px-5 py-4">
            <div class="flex flex-wrap items-center gap-3">
                <flux:select wire:model.live="filterStatus" placeholder="All Status" size="sm" class="w-40">
                    <option value="">All Status</option>
                    <option value="pending">Pending</option>
                    <option value="approved">Approved</option>
                    <option value="rejected">Rejected</option>
                    <option value="cancelled">Cancelled</option>
                </flux:select>

                <flux:input wire:model.live="filterMonth" type="month" size="sm" class="w-44" />

                @if($filterStatus || $filterMonth)
                    <button wire:click="clearFilters"
                        class="flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold text-zinc-500 hover:text-zinc-700 bg-zinc-100 dark:bg-zinc-800 hover:bg-zinc-200 rounded-lg transition">
                        <flux:icon.x-mark class="size-3.5" /> Clear
                    </button>
                @endif

                <div class="ml-auto text-xs text-zinc-400">{{ $requests->total() }} records</div>
            </div>
        </div>

        {{-- Table --}}
        <div class="bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-800 rounded-2xl overflow-hidden">
            <div class="px-6 py-4 border-b border-zinc-100 dark:border-zinc-800 flex items-center justify-between">
                <h3 class="text-sm font-bold text-zinc-900 dark:text-white flex items-center gap-2">
                    <flux:icon.home class="size-4 text-blue-500" /> WFH Request History
                </h3>
            </div>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800 bg-zinc-50/60 dark:bg-zinc-950/40">
                            <th class="py-3 pl-6 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Dates</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Duration</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Reason</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Status</th>
                            <th class="py-3 pr-4 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Reviewer</th>
                            <th class="py-3 pr-6 text-left text-[10px] font-bold uppercase tracking-widest text-zinc-400">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @forelse($requests as $req)
                            @php
                                $badge = match($req->status) {
                                    'approved'  => 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/30 dark:text-emerald-400 dark:border-emerald-800/50',
                                    'pending'   => 'bg-amber-50 text-amber-700 border-amber-200 dark:bg-amber-950/30 dark:text-amber-400 dark:border-amber-800/50',
                                    'rejected'  => 'bg-rose-50 text-rose-700 border-rose-200 dark:bg-rose-950/30 dark:text-rose-400 dark:border-rose-800/50',
                                    default     => 'bg-zinc-100 text-zinc-500 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-400 dark:border-zinc-700',
                                };
                            @endphp
                            <tr class="hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30 transition-colors" wire:key="wfh-{{ $req->id }}">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="font-semibold text-zinc-900 dark:text-white">{{ $req->dateRangeLabel() }}</div>
                                    <div class="text-[10px] text-zinc-400">{{ $req->start_date->format('l') }}</div>
                                </td>
                                <td class="py-4 pr-4 font-black text-zinc-900 dark:text-white">
                                    {{ $req->is_half_day ? 'Half day' : number_format($req->daysCount(), 0).' day(s)' }}
                                    @if($req->is_half_day)
                                        <div class="text-[10px] font-normal text-zinc-400">{{ $req->half_day_period === 'first_half' ? 'First half' : 'Second half' }}</div>
                                    @endif
                                </td>
                                <td class="py-4 pr-4 text-xs text-zinc-500 max-w-[180px] truncate" title="{{ $req->reason }}">
                                    {{ $req->reason }}
                                </td>
                                <td class="py-4 pr-4">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg border text-[10px] font-black {{ $badge }}">
                                        {{ strtoupper($req->status) }}
                                    </span>
                                </td>
                                <td class="py-4 pr-4 text-xs text-zinc-500">
                                    {{ $req->reviewer?->name ?? '—' }}
                                </td>
                                <td class="py-4 pr-6">
                                    @if($req->isPending())
                                        <button wire:click="cancel({{ $req->id }})"
                                            wire:confirm="Cancel this WFH request?"
                                            class="text-xs font-semibold text-rose-600 hover:text-rose-700 hover:underline">
                                            Cancel
                                        </button>
                                    @else
                                        <span class="text-zinc-300 dark:text-zinc-600 text-xs">—</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="py-16 text-center">
                                    <flux:icon.home class="size-10 mx-auto mb-3 text-zinc-300 dark:text-zinc-700" />
                                    <p class="text-sm font-medium text-zinc-400">No work-from-home requests found.</p>
                                    @if($filterStatus || $filterMonth)
                                        <button wire:click="clearFilters" class="text-xs text-blue-600 mt-1 hover:underline">Clear filters</button>
                                    @endif
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @if($requests->hasPages())
                <div class="px-6 py-4 border-t border-zinc-100 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-950/30">
                    {{ $requests->links() }}
                </div>
            @endif
        </div>

    </div>

    {{-- Request WFH Modal --}}
    @if($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4"
             x-data x-on:keydown.escape.window="$wire.closeModal()">
            <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="$wire.closeModal()"></div>
            <div class="relative w-full max-w-lg bg-white dark:bg-zinc-800 rounded-2xl shadow-xl ring ring-black/5 dark:ring-zinc-700 p-6 max-h-[90vh] overflow-y-auto">
                <button type="button" @click="$wire.closeModal()"
                    class="absolute top-4 right-4 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 transition-colors">
                    <svg class="size-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>

        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Request Work From Home</flux:heading>
                <flux:subheading>Submit a request to work remotely for one or more days.</flux:subheading>
            </div>

            @error('start_date')
                <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-700 dark:bg-red-950/30 dark:border-red-800 dark:text-red-400">
                    {{ $message }}
                </div>
            @enderror

            <form wire:submit="submit" class="space-y-5">
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="end_date" type="date" label="End Date" required />
                </div>

                <flux:checkbox wire:model.live="is_half_day" label="Half day" />

                @if($is_half_day)
                    <flux:select wire:model="half_day_period" label="Half Day Period" placeholder="Select period">
                        <option value="first_half">First Half</option>
                        <option value="second_half">Second Half</option>
                    </flux:select>
                @endif

                <flux:textarea
                    wire:model="reason"
                    label="Reason"
                    placeholder="Describe why you need to work from home..."
                    rows="3"
                    required
                />

                <div class="rounded-lg bg-blue-50 p-3 text-xs text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                    ℹ️ This request requires manager approval before it takes effect.
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <button type="button" @click="$wire.closeModal()" class="px-4 py-2 text-sm font-semibold text-zinc-600 dark:text-zinc-300 border border-zinc-200 dark:border-zinc-600 rounded-xl hover:bg-zinc-50 dark:hover:bg-zinc-700 transition-colors">Cancel</button>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>Submit Request</span>
                        <span wire:loading>Submitting...</span>
                    </flux:button>
                </div>
            </form>
        </div>

            </div>
        </div>
    @endif
</flux:main>
