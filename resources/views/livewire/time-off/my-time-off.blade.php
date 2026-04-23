<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">My Time Off</h1>
            <p class="pulse-page-subtitle">Manage your leave balance and applications</p>
        </div>
        <flux:button wire:click="openRequestModal" variant="primary" icon="plus">
            Request Time Off
        </flux:button>
    </div>

    {{-- Leave Balances Grid --}}
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
        @forelse($balances as $balance)
            <div class="pulse-card relative overflow-hidden">
                <div class="absolute top-0 right-0 w-1.5 h-full" style="background-color: {{ $balance->leaveType->color }}"></div>
                <div class="flex flex-col">
                    <span class="text-xs font-semibold text-zinc-400 uppercase tracking-wider">{{ $balance->leaveType->name }}</span>
                    <div class="mt-2 flex items-baseline gap-1">
                        <span class="text-3xl font-bold text-zinc-900 dark:text-white">{{ (float)($balance->allocated_days - $balance->used_days) }}</span>
                        <span class="text-sm text-zinc-500">days left</span>
                    </div>
                    <div class="mt-4 w-full bg-zinc-100 rounded-full h-1.5 dark:bg-zinc-800">
                        @php 
                            $percentage = ($balance->allocated_days > 0) ? ($balance->used_days / $balance->allocated_days) * 100 : 0;
                        @endphp
                        <div class="h-1.5 rounded-full" style="width: {{ $percentage }}%; background-color: {{ $balance->leaveType->color }}"></div>
                    </div>
                    <div class="mt-2 flex justify-between text-[10px] text-zinc-400 font-medium">
                        <span>Used: {{ (float)$balance->used_days }}</span>
                        <span>Total: {{ (float)$balance->allocated_days }}</span>
                    </div>
                </div>
            </div>
        @empty
            <div class="pulse-card lg:col-span-4 py-8 text-center text-zinc-500">
                No leave balances found for your profile. Please contact HR.
            </div>
        @endforelse
    </div>

    {{-- Request History --}}
    <flux:card class="p-0 overflow-hidden">
    <div class="px-4 border-b border-zinc-100 dark:border-zinc-800 flex gap-4">
        <button class="px-4 py-2 text-sm font-medium border-b-2 border-brand-500 text-brand-600">Leave Requests</button>
        <button class="px-4 py-2 text-sm font-medium text-zinc-500 hover:text-zinc-700">Encashment</button>
    </div>

        <div x-show="tab === 'requests'">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Leave Type</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Dates</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Days</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                            <th class="pb-3 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Reviewer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @forelse($requests as $req)
                            <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2">
                                        <div class="size-2 rounded-full" style="background-color: {{ $req->leaveType->color }}"></div>
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $req->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                    {{ $req->start_date->format('M d, Y') }} - {{ $req->end_date->format('M d, Y') }}
                                </td>
                                <td class="py-4 pr-4 font-semibold text-zinc-900 dark:text-white">{{ (float)$req->days }}</td>
                                <td class="py-4 pr-4">
                                    <span class="badge-{{ $req->status }}">{{ strtoupper($req->status) }}</span>
                                </td>
                                <td class="py-4 pr-6">
                                    @if($req->reviewer)
                                        <span class="text-xs text-zinc-500">{{ $req->reviewer->name }}</span>
                                    @else
                                        <span class="text-xs text-zinc-400 lowercase">Pending ...</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-12 text-center text-zinc-400 italic font-medium tracking-tight">You haven't requested any time off yet.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                <div class="p-4 border-t border-zinc-50 dark:border-zinc-800">
                    {{ $requests->links() }}
                </div>
            </div>
        </div>

        <div x-show="tab === 'encashments'">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-zinc-100 dark:border-zinc-800">
                            <th class="pb-3 pl-6 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Leave Type</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Requested Days</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Status</th>
                            <th class="pb-3 pr-4 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Payout Month</th>
                            <th class="pb-3 pr-6 text-left text-xs font-semibold uppercase tracking-wide text-zinc-400 dark:text-zinc-500">Reviewer</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/60">
                        @forelse($encashments as $enc)
                            <tr class="group hover:bg-zinc-50/70 dark:hover:bg-zinc-800/30 transition-colors">
                                <td class="py-4 pl-6 pr-4">
                                    <div class="flex items-center gap-2">
                                        <div class="size-2 rounded-full" style="background-color: {{ $enc->leaveType->color }}"></div>
                                        <span class="font-medium text-zinc-900 dark:text-white">{{ $enc->leaveType->name }}</span>
                                    </div>
                                </td>
                                <td class="py-4 pr-4 font-semibold text-zinc-900 dark:text-white">{{ (float)$enc->requested_days }}</td>
                                <td class="py-4 pr-4">
                                    <span class="badge-{{ $enc->status === 'processed' ? 'on_time' : $enc->status }}">{{ strtoupper($enc->status) }}</span>
                                </td>
                                <td class="py-4 pr-4 text-zinc-600 dark:text-zinc-300">
                                    {{ $enc->payout_month ?? '--' }}
                                </td>
                                <td class="py-4 pr-6">
                                    @if($enc->reviewer)
                                        <span class="text-xs text-zinc-500">{{ $enc->reviewer->name }}</span>
                                    @else
                                        <span class="text-xs text-zinc-400 lowercase">Pending ...</span>
                                    @endif
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="py-12 text-center text-zinc-400 italic font-medium tracking-tight">No encashment requests found.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </flux:card>

    {{-- Request Leave Modal --}}
    <flux:modal wire:model="showRequestModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Request Time Off</flux:heading>
                <flux:subheading>Submit a new leave application for review.</flux:subheading>
            </div>

            <form wire:submit="submitRequest" class="space-y-5">
                <flux:select wire:model="leave_type_id" label="Leave Type" placeholder="Select type..." required>
                    @foreach($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }} ({{ $type->is_paid ? 'Paid' : 'Unpaid' }})</option>
                    @endforeach
                </flux:select>

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="start_date" type="date" label="Start Date" required />
                    <flux:input wire:model="end_date" type="date" label="End Date" required />
                </div>

                <flux:textarea wire:model="reason" label="Reason" placeholder="Please describe why you need time off..." rows="3" required />

                <div class="flex justify-end gap-3 pt-4">
                    <flux:modal.close>
                        <flux:button variant="ghost">Cancel</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary">Submit Request</flux:button>
                </div>
            </form>
        </div>
    </flux:modal>

    {{-- Encashment Modal --}}
    <flux:modal name="encashment-modal" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Encash Leave Balance</flux:heading>
                <flux:subheading>Convert your unused paid leave days into salary payout.</flux:subheading>
            </div>

            <div class="space-y-4">
                <flux:select wire:model="encash_leave_type_id" label="Select Leave Type" required>
                    @foreach($encashableTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="encash_days" label="Days to Encash" type="number" step="0.5" suffix="Days" required />
                
                <p class="text-[10px] text-zinc-400 italic leading-tight uppercase font-medium tracking-wider">
                    * ONLY CSL LEAVES ARE ELIGIBLE FOR ENCASHMENT AS PER COMPANY POLICY.
                </p>
            </div>

            <div class="flex gap-2 justify-end mt-6">
                <flux:button @click="$flux.modal('encashment-modal').close()">Cancel</flux:button>
                <flux:button wire:click="submitEncashment" variant="primary">Submit Request</flux:button>
            </div>
        </div>
    </flux:modal>
</flux:main>
