<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-['Inter'] md:p-6 dark:bg-[#0B1220]">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h1 class="text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">Leave Regularisation</h1>
            <p class="mt-1 text-sm text-[#667085] dark:text-zinc-400">
                Convert a past absence into approved leave. Requests follow the same
                manager → HR → admin approval as any other regularisation.
            </p>
        </div>

        @can('create_leave_regularisation')
            <flux:button wire:click="openForm" variant="primary" icon="plus">Regularise leave</flux:button>
        @endcan
    </div>

    <div class="grid grid-cols-2 gap-4 lg:grid-cols-5">
        @foreach([
            ['Pending',    $counts['pending'],    'clock',        'blue'],
            ['Approved',   $counts['approved'],   'check-circle', 'emerald'],
            ['Rejected',   $counts['rejected'],   'x-circle',     'rose'],
            ['Cancelled',  $counts['cancelled'],  'no-symbol',    'zinc'],
            ['This month', $counts['this_month'], 'calendar',     'orange'],
        ] as [$label, $value, $icon, $tone])
            <div class="rounded-2xl border border-[#EAECF0] bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="flex items-center gap-2">
                    <div class="flex size-8 items-center justify-center rounded-lg bg-{{ $tone }}-50 text-{{ $tone }}-500 dark:bg-white/5">
                        <flux:icon :name="$icon" class="size-4" />
                    </div>
                    <span class="text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">{{ $label }}</span>
                </div>
                <div class="mt-2 text-2xl font-extrabold text-[#101828] dark:text-white">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Raise a request --}}
    @if($showForm)
        <div class="rounded-2xl border border-[#EAECF0] bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <h2 class="text-base font-bold text-[#101828] dark:text-white">New leave regularisation</h2>
            <p class="mt-0.5 text-sm text-[#667085]">
                For a date already past. Regularisation is allowed up to {{ $windowDays }} days back.
            </p>

            <div class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
                <x-clean-select model="formEmployeeId" label="Employee" :live="false" placeholder="Select employee…"
                    :options="$employees->map(fn ($e) => ['value' => $e->id, 'label' => $e->user?->name ?? $e->employee_id])->all()" />
                <x-clean-select model="formLeaveTypeId" label="Leave type" :live="false" placeholder="Select leave type…"
                    :options="$leaveTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all()" />
                <flux:input wire:model="formDuration" type="number" step="0.5" min="0.5" label="Duration (days)" placeholder="Leave blank to calculate" />
                <flux:input wire:model="formFrom" type="date" label="From date" />
                <flux:input wire:model="formTo" type="date" label="To date" />
                <flux:input wire:model="formReason" label="Reason" placeholder="Why was this absence not booked?" />
                <div class="md:col-span-2 lg:col-span-3">
                    <flux:textarea wire:model="formRemarks" label="Remarks (optional)" rows="2" />
                </div>
            </div>

            @error('formEmployeeId') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror
            @error('formReason') <p class="mt-2 text-sm text-rose-600">{{ $message }}</p> @enderror

            <div class="mt-4 flex justify-end gap-2">
                <flux:button wire:click="$set('showForm', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="submitRequest" variant="primary" wire:loading.attr="disabled" wire:target="submitRequest">
                    <span wire:loading.remove wire:target="submitRequest">Submit for approval</span>
                    <span wire:loading wire:target="submitRequest">Submitting…</span>
                </flux:button>
            </div>
        </div>
    @endif

    {{-- Filters --}}
    <div class="flex flex-wrap items-end gap-3">
        <x-clean-select model="statusFilter" label="Status" :live="true"
            :options="[
                ['value' => 'pending', 'label' => 'Pending'],
                ['value' => 'approved', 'label' => 'Approved'],
                ['value' => 'rejected', 'label' => 'Rejected'],
                ['value' => 'cancelled', 'label' => 'Cancelled'],
                ['value' => '', 'label' => 'All'],
            ]" />
        <x-clean-select model="employeeFilter" label="Employee" :live="true"
            :options="array_merge([['value' => '', 'label' => 'All employees']], $employees->map(fn ($e) => ['value' => $e->id, 'label' => $e->user?->name ?? $e->employee_id])->all())" />
    </div>

    <div class="overflow-hidden rounded-2xl border border-[#EAECF0] bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full min-w-max text-sm">
                <thead>
                    <tr class="border-b border-[#EAECF0] bg-[#F9FAFB] text-left dark:border-white/10 dark:bg-white/[0.02]">
                        @foreach(['Employee', 'Date', 'Attendance', 'Leave type', 'Duration', 'Reason', 'Requested by', 'Status', ''] as $heading)
                            <th class="whitespace-nowrap px-4 py-3 text-[10px] font-bold uppercase tracking-widest text-[#98A2B3]">{{ $heading }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-[#F2F4F7] dark:divide-white/5">
                    @forelse($requests as $req)
                        @php
                            [$badge, $tone] = match($req->status) {
                                'approved'  => [$req->stageLabel(), 'bg-emerald-50 text-emerald-700 ring-emerald-600/20'],
                                'rejected'  => ['Rejected', 'bg-rose-50 text-rose-700 ring-rose-600/20'],
                                'cancelled' => ['Cancelled', 'bg-zinc-100 text-zinc-600 ring-zinc-500/20'],
                                default     => [$req->stageLabel(), 'bg-blue-50 text-blue-700 ring-blue-600/20'],
                            };
                        @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 font-semibold text-[#101828] dark:text-zinc-100">{{ $req->employee?->user?->name ?? '—' }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-[#667085] dark:text-zinc-400">
                                {{ $req->from_date?->format('d M Y') ?? $req->work_date?->format('d M Y') }}
                                @if($req->to_date && $req->from_date && ! $req->to_date->equalTo($req->from_date))
                                    <span class="text-[#98A2B3]">→ {{ $req->to_date->format('d M Y') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-[#667085] dark:text-zinc-400">
                                {{-- What the day looked like before, so the correction is legible. --}}
                                {{ $req->previous_attendance_status ?? $req->attendance?->status ?? 'No record' }}
                            </td>
                            <td class="px-4 py-3 text-[#667085] dark:text-zinc-400">{{ $req->leaveType?->name ?? '—' }}</td>
                            <td class="px-4 py-3 tabular-nums text-[#667085] dark:text-zinc-400">{{ $req->duration ?? 1 }}</td>
                            <td class="max-w-64 px-4 py-3 text-[#667085] dark:text-zinc-400">
                                <span class="line-clamp-2">{{ $req->reason }}</span>
                            </td>
                            <td class="px-4 py-3 text-[#667085] dark:text-zinc-400">{{ $req->reviewer?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex whitespace-nowrap rounded-full px-2 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $tone }}">{{ $badge }}</span>
                                @if($req->status === 'approved' && $req->previous_balance !== null)
                                    <div class="mt-1 text-[10px] text-[#98A2B3]">
                                        Balance {{ $req->previous_balance }} → {{ $req->new_balance }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($req->status === 'pending')
                                    <div class="flex items-center justify-end gap-1">
                                        @can('approve_leave_regularisation')
                                            <flux:tooltip content="Approve at {{ $req->stageLabel() }}">
                                                <flux:button wire:click="approve({{ $req->id }})" variant="ghost" size="sm" icon="check" class="text-emerald-600" />
                                            </flux:tooltip>
                                            <flux:tooltip content="Reject">
                                                <flux:button wire:click="startReject({{ $req->id }})" variant="ghost" size="sm" icon="x-mark" class="text-rose-500" />
                                            </flux:tooltip>
                                        @endcan
                                        <flux:tooltip content="Withdraw this request">
                                            <flux:button wire:click="cancel({{ $req->id }})"
                                                wire:confirm="Withdraw this regularisation request?"
                                                variant="ghost" size="sm" icon="no-symbol" class="text-zinc-400" />
                                        </flux:tooltip>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-10 text-center text-sm text-[#98A2B3]">No regularisation requests here.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="border-t border-[#EAECF0] px-4 py-3 dark:border-white/10">{{ $requests->links() }}</div>
    </div>

    @if($reviewId)
        <div class="rounded-2xl border border-rose-200 bg-rose-50 p-4 dark:border-rose-500/20 dark:bg-rose-500/5">
            <h3 class="text-sm font-bold text-rose-800 dark:text-rose-300">Reject this regularisation</h3>
            <p class="mt-1 text-xs text-rose-700/80 dark:text-rose-300/80">The employee is told what you write here.</p>
            <div class="mt-3 flex flex-wrap items-end gap-2">
                <flux:input wire:model="reviewComment" label="Reason" placeholder="Why is this being rejected?" class="min-w-72 flex-1" />
                <flux:button wire:click="confirmReject" variant="danger">Reject</flux:button>
                <flux:button wire:click="$set('reviewId', null)" variant="ghost">Cancel</flux:button>
            </div>
        </div>
    @endif

    <div class="flex items-start gap-3 rounded-xl border border-orange-100 bg-orange-50/60 px-4 py-3.5 text-sm text-[#7C4A17] dark:border-orange-500/20 dark:bg-orange-500/5 dark:text-orange-300">
        <flux:icon.information-circle class="mt-0.5 size-4 shrink-0" />
        <span>
            Regularisation corrects a date that has already passed. On final approval the leave balance is
            deducted and the day is marked as leave — <strong>raw biometric punches are never modified</strong>.
            For previous-year entitlement use <strong>Carry Forward</strong>; for future dates, a normal leave request.
        </span>
    </div>
</flux:main>
