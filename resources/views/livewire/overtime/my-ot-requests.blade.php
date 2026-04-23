<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My OT Requests</h1>
            <p class="pulse-page-subtitle">Submit and track your overtime pre-approval requests</p>
        </div>
        <flux:button wire:click="openModal" variant="primary" icon="plus">
            Request OT
        </flux:button>
    </div>

    {{-- History Table --}}
    <div class="pulse-card">
        <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-6">OT Request History</h3>

        <div class="overflow-x-auto -mx-6">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-100 dark:border-zinc-800">
                        <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Work Date</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Time Window</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Hrs Requested</th>
                        <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Status</th>
                        <th class="pb-3 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Reviewer</th>
                        <th class="pb-3 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400">Action</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                    @forelse($requests as $req)
                        <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors" wire:key="ot-{{ $req->id }}">
                            <td class="py-4 pl-6 pr-4 font-medium text-zinc-900 dark:text-white">
                                {{ $req->work_date->format('M d, Y') }}
                            </td>
                            <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                {{ $req->start_time }} – {{ $req->end_time }}
                            </td>
                            <td class="py-4 pr-4 font-semibold text-zinc-900 dark:text-white">
                                {{ number_format($req->requested_hours, 2) }} hrs
                            </td>
                            <td class="py-4 pr-4">
                                <span class="badge-{{ $req->status }}">{{ strtoupper($req->status) }}</span>
                            </td>
                            <td class="py-4 pr-6 text-xs text-zinc-500">
                                {{ $req->reviewer?->name ?? 'Pending Review' }}
                            </td>
                            <td class="py-4 pr-6">
                                @if($req->isPending())
                                    <flux:button
                                        wire:click="cancel({{ $req->id }})"
                                        wire:confirm="Cancel this OT request?"
                                        variant="ghost"
                                        size="xs"
                                        class="text-red-500"
                                    >Cancel</flux:button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="py-12 text-center text-zinc-400">
                                No OT requests yet. Click "Request OT" to submit one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-4 border-t border-zinc-50 pt-3 dark:border-zinc-800">
            {{ $requests instanceof \Illuminate\Pagination\LengthAwarePaginator ? $requests->links() : '' }}
        </div>
    </div>

    {{-- Request OT Modal --}}
    <flux:modal wire:model="showModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Request Overtime</flux:heading>
                <flux:subheading>Submit a pre-approval request for overtime work.</flux:subheading>
            </div>

            <form wire:submit="submit" class="space-y-5">
                <flux:input wire:model="work_date" type="date" label="Work Date" required />

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_time" type="time" label="OT Start Time" required />
                    <flux:input wire:model="end_time" type="time" label="OT End Time" required />
                </div>

                <flux:textarea
                    wire:model="reason"
                    label="Reason for OT"
                    placeholder="Describe the work that requires overtime..."
                    rows="3"
                    required
                />

                <div class="rounded-lg bg-blue-50 p-3 text-xs text-blue-700 dark:bg-blue-950/40 dark:text-blue-300">
                    ℹ️ OT is calculated as hours worked beyond 9 hrs/day at ₹100/hr. This request requires manager approval.
                </div>

                <div class="flex justify-end gap-3 pt-4">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" wire:loading.attr="disabled">
                        <span wire:loading.remove>Submit Request</span>
                        <span wire:loading>Submitting...</span>
                    </flux:button>
                </div>
            </form>
        </div>
    </flux:modal>
</flux:main>
