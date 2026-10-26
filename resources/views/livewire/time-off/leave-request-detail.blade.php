<flux:main class="min-h-screen space-y-5 bg-[#F7F8FA] p-4 font-sans md:p-6 dark:bg-[#0B1220]">
    @php
        $P = \App\Services\Leave\LeaveRequestPresenter::class;
        $r = $this->request;
        $employee = $r->employee;
        $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        [$statusLabel, $statusColor] = $P::status($r->status);
        $paid = $P::paid($r);
        $unpaid = $P::unpaid($r);
        $dates = $this->dates;
        $halfUnits = $r->isCombined()
            ? $r->dayAllocations->filter(fn ($d) => \App\Models\LeaveRequestDay::isHalf((string) $d->allocation_type))->count()
            : ($r->is_half_day ? 1 : 0);
        $excluded = $dates->where('excluded', true);
        $impact = $this->balanceImpact;
        $pending = $P::isPending($r);
        $card = 'ui-surface p-5';
        $h2 = 'text-base font-semibold text-zinc-900 dark:text-white';
        $dt = 'text-xs text-zinc-500 dark:text-zinc-400';
        $dd = 'text-sm font-medium text-zinc-900 dark:text-white';
    @endphp

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <a href="{{ route('time-off.leave-requests') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"><flux:icon.arrow-left class="size-3" /> Leave Requests</a>
            <h1 class="mt-1 flex flex-wrap items-center gap-2 text-2xl font-extrabold tracking-tight text-[#101828] dark:text-white">
                {{ $P::reference($r) }}
                <x-pulse.badge :color="$statusColor">{{ $statusLabel }}</x-pulse.badge>
                @if($r->isCombined())<x-pulse.badge color="violet">Combined</x-pulse.badge>@elseif($P::isLegacy($r))<x-pulse.badge>Legacy</x-pulse.badge>@endif
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $employee?->user?->name ?? 'Employee' }} · {{ $employee?->employee_id }} · {{ $employee?->department?->name ?? 'No department' }}</p>
        </div>
        <div class="flex flex-wrap gap-2" data-request-actions>
            @if($this->canDecide)
                <flux:button size="sm" variant="primary" icon="check" wire:click="confirm('approve')">Approve</flux:button>
                <flux:button size="sm" icon="x-mark" wire:click="confirm('reject')">Reject</flux:button>
            @endif
            @if($this->canCancel)
                <flux:button size="sm" variant="ghost" wire:click="confirm('cancel')">Cancel Leave</flux:button>
            @endif
            @if($this->canCorrect)
                <flux:button size="sm" variant="ghost" :href="route('time-off.leave-management.employee', ['employee' => $employee, 'tab' => 'requests'])" wire:navigate>Correct</flux:button>
            @endif
            @if($this->canViewBalances)
                <flux:button size="sm" variant="ghost" icon="scale" :href="route('time-off.leave-management.employee', ['employee' => $employee])" wire:navigate>Leave Balance</flux:button>
            @endif
            @if($this->canView360)
                <flux:button size="sm" variant="ghost" icon="user-circle" :href="route('employees.360', $employee)" wire:navigate>Employee 360</flux:button>
            @endif
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-500/10 dark:text-emerald-300">{{ session('success') }}</div>
    @endif
    @error('action')
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-800 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300" role="alert">{{ $message }}</div>
    @enderror

    {{-- The employee asks to cancel this APPROVED leave: it stays approved until HR decides. --}}
    @if($pendingCancellation = $r->pendingCancellation)
        <div class="ui-surface space-y-3 p-5" style="border-left: 4px solid #f59e0b" data-cancellation-request>
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="flex flex-wrap items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white">Cancellation requested <x-pulse.badge color="amber">Awaiting HR</x-pulse.badge></p>
                    <p class="mt-1 text-sm text-zinc-700 dark:text-zinc-200">“{{ $pendingCancellation->reason }}”</p>
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $pendingCancellation->requester?->name ?? 'The employee' }} · {{ $pendingCancellation->created_at?->toImmutable()->userDateTime() }} · The leave stays approved and deducted until this is decided.</p>
                </div>
                @if($this->canDecideCancellation && ! in_array($confirming, ['approve_cancellation', 'reject_cancellation'], true))
                    <div class="flex flex-wrap gap-2">
                        <flux:button size="sm" variant="primary" wire:click="confirm('approve_cancellation')">Approve Cancellation</flux:button>
                        <flux:button size="sm" wire:click="confirm('reject_cancellation')">Reject</flux:button>
                    </div>
                @endif
            </div>
            @if($this->canDecideCancellation && in_array($confirming, ['approve_cancellation', 'reject_cancellation'], true))
                <div class="space-y-3 border-t border-zinc-100 pt-3 dark:border-white/5">
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $confirming === 'approve_cancellation' ? 'The leave is cancelled and its paid days return to the balance; attendance and payroll follow.' : 'The leave stays approved. The employee sees your reason.' }}</p>
                    <flux:textarea wire:model="cancellationNote" rows="2" :label="$confirming === 'reject_cancellation' ? 'Reason for the employee' : 'Note (optional)'" />
                    <div class="flex flex-wrap gap-2">
                        @if($confirming === 'approve_cancellation')
                            <flux:button size="sm" variant="primary" wire:click="approveCancellation" wire:loading.attr="disabled" wire:target="approveCancellation">Confirm — Cancel the Leave</flux:button>
                        @else
                            <flux:button size="sm" variant="danger" wire:click="rejectCancellation" wire:loading.attr="disabled" wire:target="rejectCancellation">Confirm — Keep the Leave</flux:button>
                        @endif
                        <flux:button size="sm" variant="ghost" wire:click="confirm('')">Back</flux:button>
                    </div>
                </div>
            @endif
        </div>
    @endif

    {{-- Confirmation: approve / reject / cancel --}}
    @if($confirming && ! in_array($confirming, ['approve_cancellation', 'reject_cancellation'], true))
        <div class="ui-surface space-y-3 p-5" style="border-left: 4px solid {{ $confirming === 'approve' ? '#10b981' : '#f43f5e' }}" data-confirm="{{ $confirming }}">
            <p class="text-sm font-semibold text-zinc-900 dark:text-white">
                {{ ['approve' => 'Approve this leave request?', 'reject' => 'Reject this leave request?', 'cancel' => 'Cancel this leave?'][$confirming] }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ ['approve' => 'The paid days are deducted from the balance once, and attendance and payroll follow.', 'reject' => 'Nothing is deducted. The employee sees your reason.', 'cancel' => 'Any deducted days are returned to the balance; attendance and payroll follow.'][$confirming] }}
            </p>
            @if($confirming === 'cancel')
                <flux:textarea wire:model="cancelReason" rows="2" label="Reason" placeholder="Why is this leave being cancelled?" />
            @else
                <flux:textarea wire:model="comment" rows="2" :label="$confirming === 'reject' ? 'Reason for rejecting' : 'Comment (optional)'" />
            @endif
            <div class="flex flex-wrap gap-2">
                @if($confirming === 'approve')
                    <flux:button size="sm" variant="primary" wire:click="approve" wire:loading.attr="disabled" wire:target="approve">Confirm Approval</flux:button>
                @elseif($confirming === 'reject')
                    <flux:button size="sm" variant="danger" wire:click="reject" wire:loading.attr="disabled" wire:target="reject">Confirm Rejection</flux:button>
                @else
                    <flux:button size="sm" variant="danger" wire:click="cancel" wire:loading.attr="disabled" wire:target="cancel">Confirm Cancellation</flux:button>
                @endif
                <flux:button size="sm" variant="ghost" wire:click="confirm('')">Back</flux:button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 items-start gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            {{-- Request summary --}}
            <section class="{{ $card }}" data-section="summary">
                <h2 class="{{ $h2 }}">Request Summary</h2>
                <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-3">
                    <div><dt class="{{ $dt }}">Leave category</dt><dd class="{{ $dd }}">{{ $P::category($r) }}</dd></div>
                    <div><dt class="{{ $dt }}">Reason / subtype</dt><dd class="{{ $dd }}">{{ $P::reason($r) ?? '—' }}</dd></div>
                    <div><dt class="{{ $dt }}">Request mode</dt><dd class="{{ $dd }}">{{ $r->isCombined() ? 'Combined Leave' : ($r->is_half_day ? 'Half day' : 'Standard') }}</dd></div>
                    <div><dt class="{{ $dt }}">Date range</dt><dd class="{{ $dd }}">{{ $P::dates($r) }}</dd></div>
                    <div><dt class="{{ $dt }}">Days</dt><dd class="{{ $dd }} tabular-nums">{{ $fmt($r->days) }}</dd></div>
                    <div><dt class="{{ $dt }}">Paid</dt><dd class="{{ $dd }} tabular-nums">{{ $fmt($paid) }}</dd></div>
                    <div><dt class="{{ $dt }}">Unpaid</dt><dd class="{{ $dd }} tabular-nums {{ $unpaid > 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ $fmt($unpaid) }}</dd></div>
                    <div><dt class="{{ $dt }}">Half-day units</dt><dd class="{{ $dd }} tabular-nums">{{ $halfUnits }}</dd></div>
                    <div><dt class="{{ $dt }}">Excluded days</dt><dd class="{{ $dd }}">{{ $excluded->isEmpty() ? 'None' : $excluded->count().' ('.$excluded->pluck('status')->unique()->join(', ').')' }}</dd></div>
                    <div><dt class="{{ $dt }}">Submitted</dt><dd class="{{ $dd }}">{{ $r->created_at->toImmutable()->userDateTime() }}</dd></div>
                    @if($r->applied_by_user_id)
                        <div><dt class="{{ $dt }}">Filed by</dt><dd class="{{ $dd }}">HR on the employee's behalf</dd></div>
                    @endif
                </dl>
                <div class="mt-4 space-y-2 border-t border-zinc-100 pt-3 text-sm dark:border-white/5">
                    <p><span class="{{ $dt }} block">Reason</span><span class="text-zinc-800 dark:text-zinc-100">{{ $r->reason ?: '—' }}</span></p>
                    @foreach(array_filter(['Reviewer comment' => $r->reviewer_comment, 'HR comment' => $r->hr_reviewer_comment, 'HR remark' => $r->hr_remark, 'HR internal note' => $showInternal ? $r->hr_internal_note : null]) as $label => $remark)
                        <p><span class="{{ $dt }} block">{{ $label }}</span><span class="text-zinc-800 dark:text-zinc-100">{{ $remark }}</span></p>
                    @endforeach
                </div>
            </section>

            {{-- Date allocation --}}
            <section class="{{ $card }}" data-section="dates">
                <h2 class="{{ $h2 }}">Date Allocation</h2>
                @php
                    $calendarUnits = $dates->where('excluded', false)->count() - ($r->is_half_day && ! $r->isCombined() ? 0.5 : 0);
                @endphp
                @if(! $r->isCombined() && $dates->isNotEmpty() && abs($calendarUnits - (float) $r->days) > 0.001)
                    {{-- The stored request is never recalculated here; the difference is only explained. --}}
                    <p class="mt-1 text-xs text-amber-700 dark:text-amber-300" data-days-differ>Stored as {{ $fmt($r->days) }} {{ Str::plural('day', (float) $r->days == 1 ? 1 : 2) }}; today's calendar counts {{ $fmt($calendarUnits) }} working {{ Str::plural('day', $calendarUnits == 1 ? 1 : 2) }} in this range. The stored figure is what was charged.</p>
                @else
                    <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $r->isCombined() ? 'As allocated on the request.' : 'As the working calendar reads this range.' }}</p>
                @endif
                @if($dates->isEmpty())
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No dates to show.</p>
                @else
                    <ul class="mt-3 divide-y divide-zinc-100 text-sm dark:divide-white/5">
                        @foreach($dates as $d)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2" data-date-row="{{ $d['date']->toDateString() }}">
                                <span class="flex items-center gap-3">
                                    <span class="w-24 font-medium tabular-nums text-zinc-900 dark:text-white">{{ $d['date']->toImmutable()->userShortDate() }} <span class="text-zinc-500 dark:text-zinc-400">{{ $d['day'] }}</span></span>
                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $d['status'] }}</span>
                                </span>
                                <span class="flex items-center gap-2">
                                    <x-pulse.badge :color="$d['excluded'] ? 'zinc' : (str_contains($d['allocation'], 'Unpaid') ? 'rose' : 'emerald')">{{ $d['allocation'] }}</x-pulse.badge>
                                    @if($d['session'])<span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $d['session'] }}</span>@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Payroll and attendance impact --}}
            <section class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div class="{{ $card }}" data-section="payroll">
                    <h2 class="{{ $h2 }}">Payroll Impact</h2>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-200">
                        @if(in_array($r->status, ['rejected', 'cancelled'], true))
                            None — the request is {{ $statusLabel }}.
                        @elseif($unpaid > 0)
                            {{ $pending ? 'If approved: ' : '' }}{{ $fmt($unpaid) }} {{ Str::plural('day', $unpaid == 1 ? 1 : 2) }} of Leave Without Pay, deducted from pay for {{ $r->start_date->format('M Y') }}{{ $r->end_date->format('Y-m') !== $r->start_date->format('Y-m') ? ' – '.$r->end_date->format('M Y') : '' }}.
                        @else
                            {{ $pending ? 'If approved: ' : '' }}paid leave — no pay deduction.
                        @endif
                    </p>
                </div>
                <div class="{{ $card }}" data-section="attendance">
                    <h2 class="{{ $h2 }}">Attendance Impact</h2>
                    @php $leaveDays = $dates->where('excluded', false)->count(); @endphp
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-200">
                        @if(in_array($r->status, ['rejected', 'cancelled'], true))
                            None — attendance is unaffected.
                        @else
                            {{ $pending ? 'If approved: ' : '' }}marked On Leave for {{ $leaveDays }} working {{ Str::plural('day', $leaveDays) }}{{ $halfUnits ? ' ('.$halfUnits.' half '.Str::plural('day', $halfUnits).')' : '' }}{{ $excluded->isNotEmpty() ? '; weekly offs and holidays in the range are not leave' : '' }}.
                        @endif
                    </p>
                </div>
            </section>

            {{-- Ledger references --}}
            <section class="{{ $card }}" data-section="ledger">
                <h2 class="{{ $h2 }}">Ledger</h2>
                @if($this->ledgerEntries->isEmpty())
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $r->status === 'approved' ? 'No ledger movement is recorded for this request (it charges no balance, or predates the ledger).' : 'Nothing is posted until the request is approved.' }}</p>
                @else
                    <ul class="mt-3 divide-y divide-zinc-100 text-sm dark:divide-white/5">
                        @foreach($this->ledgerEntries as $entry)
                            <li class="flex flex-wrap items-center justify-between gap-2 py-2">
                                <span>#{{ $entry->id }} · {{ $entry->effective_date?->toImmutable()->userDate() }} · {{ ucwords(str_replace('_', ' ', $entry->entry_type)) }} · {{ $entry->leaveType?->name }}</span>
                                <span class="font-semibold tabular-nums {{ (float) $entry->days < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400' }}">{{ (float) $entry->days > 0 ? '+' : '' }}{{ $fmt($entry->days) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- Audit trail --}}
            <section class="{{ $card }}" data-section="audit">
                <h2 class="{{ $h2 }}">Approval History &amp; Audit Trail</h2>
                @if($this->history->isEmpty())
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">No recorded actions yet beyond submission.</p>
                @else
                    <ol class="mt-3 space-y-3 text-sm">
                        @foreach($this->history as $event)
                            <li class="flex gap-3">
                                <span class="mt-1.5 size-2 shrink-0 rounded-full bg-orange-400"></span>
                                <span class="min-w-0">
                                    <span class="block font-medium text-zinc-900 dark:text-white">{{ ucwords(strtolower(str_replace(['LEAVE_', '_'], ['', ' '], (string) ($event->event ?: $event->action)))) }}</span>
                                    <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $event->user?->name ?? 'System' }} · {{ $event->created_at?->toImmutable()->userDateTime() }}</span>
                                    @if($event->reason)<span class="block text-xs text-zinc-600 dark:text-zinc-300">“{{ $event->reason }}”</span>@endif
                                </span>
                            </li>
                        @endforeach
                    </ol>
                @endif
            </section>
        </div>

        <div class="space-y-5">
            {{-- Balance impact --}}
            <section class="{{ $card }}" data-section="balance">
                <h2 class="{{ $h2 }}">Balance Impact</h2>
                @if($impact === null)
                    <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-300">No paid balance is used{{ $unpaid > 0 ? ': the days are unpaid' : '' }}.</p>
                @else
                    <p class="{{ $dt }} mt-1">{{ $impact['type'] }}</p>
                    <dl class="mt-3 space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-300">Balance before request</dt><dd class="font-semibold tabular-nums">{{ $fmt($impact['before']) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-zinc-600 dark:text-zinc-300">This request</dt><dd class="font-semibold tabular-nums text-rose-600 dark:text-rose-400">{{ $impact['paid'] > 0 ? '−'.$fmt($impact['paid']) : '0' }}</dd></div>
                        <div class="flex justify-between border-t border-zinc-100 pt-2 dark:border-white/5"><dt class="font-medium text-zinc-900 dark:text-white">Remaining</dt><dd class="font-bold tabular-nums {{ $impact['after'] < 0 ? 'text-rose-600 dark:text-rose-400' : '' }}">{{ $fmt($impact['after']) }}</dd></div>
                    </dl>
                    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $impact['note'] }}</p>
                @endif
                @if($this->canAdjust)
                    <flux:button size="sm" class="mt-3 w-full" icon="adjustments-horizontal" :href="route('time-off.leave-management.employee', ['employee' => $employee])" wire:navigate>Adjust Leave Balance</flux:button>
                @endif
            </section>

            {{-- Approval path --}}
            <section class="{{ $card }}" data-section="approval">
                <h2 class="{{ $h2 }}">Approval</h2>
                <ol class="mt-3 space-y-3 text-sm">
                    @foreach($path['steps'] as $step)
                        <li class="flex gap-3">
                            <span @class(['mt-1 size-2.5 shrink-0 rounded-full', 'bg-emerald-500' => $step['state'] === 'done', 'bg-amber-400' => $step['state'] === 'current', 'bg-zinc-300 dark:bg-zinc-600' => $step['state'] === 'next'])></span>
                            <span><span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $step['label'] }}</span><span class="block font-medium text-zinc-900 dark:text-white">{{ $step['actor'] }}</span></span>
                        </li>
                    @endforeach
                </ol>
                @if($pending)
                    <p class="mt-3 text-xs text-zinc-500 dark:text-zinc-400">Current approver: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $path['current'] }}</span></p>
                @endif
                @if($path['escalated'])
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">{{ $path['escalated'] }}</p>
                @endif
            </section>

            {{-- Attachments --}}
            <section class="{{ $card }}" data-section="attachments">
                <h2 class="{{ $h2 }}">Attachments</h2>
                @if($r->attachments->isEmpty())
                    <p class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">None.</p>
                @else
                    <ul class="mt-3 space-y-2 text-sm">
                        @foreach($r->attachments as $att)
                            <li><a href="{{ $att->url() }}" target="_blank" rel="noopener" class="inline-flex items-center gap-2 text-orange-600 hover:underline dark:text-orange-400"><flux:icon :name="$att->icon()" class="size-4" /> {{ $att->typeLabel() }} — {{ $att->original_name }}</a></li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>
</flux:main>
