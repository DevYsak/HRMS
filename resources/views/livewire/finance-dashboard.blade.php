@php
    $money = fn ($n) => '₹'.number_format((float) $n, 0);
    $cycleLabel = fn (?string $c) => match ($c) { 'cycle_a' => 'Cycle A', 'cycle_b' => 'Cycle B', default => ucfirst((string) $c) };
    $statusColor = fn (?string $s) => match ($s) { 'finalized' => 'green', 'pending_finance' => 'amber', default => 'zinc' };
    $statusLabel = fn (?string $s) => match ($s) { 'finalized' => 'Finalised', 'pending_finance' => 'Awaiting finance', 'draft' => 'Draft', default => ucfirst(str_replace('_', ' ', (string) $s)) };
@endphp

<flux:main>
    <div class="mx-auto w-full max-w-[1400px] space-y-5">
        <x-pulse.dashboard-header title="Finance" :subtitle="'Payroll queue, payables and compensation · '.$period->format('F Y')">
            <x-slot:actions>
                <flux:input wire:model.live="month" type="month" size="sm" aria-label="Month" />
            </x-slot:actions>
        </x-pulse.dashboard-header>

        {{-- What is waiting on Finance --}}
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <x-pulse.kpi-card label="Payroll runs awaiting finance" :value="$awaitingFinance->count()" icon="banknotes" accent="amber"
                :href="$canApproveFinance ? route('payroll.finance-approve') : null" sub="Sign-off queue" />
            <x-pulse.kpi-card label="OT payable (unpaid)" :value="$money($otPayable['amount'])" icon="clock" accent="blue"
                :sub="$otPayable['hours'].' h · '.$otPayable['people'].' people'" />
            <x-pulse.kpi-card label="Incentives pending" :value="$incentives['pending']" icon="sparkles" accent="indigo"
                :href="$canRunPayroll ? route('payroll.incentives') : null" :sub="$money($incentives['approved_amount']).' approved this month'" />
            <x-pulse.kpi-card label="Reimbursements pending" :value="$reimbursements['pending']" icon="receipt-percent" accent="emerald"
                :href="$canRunPayroll ? route('payroll.reimbursements') : null" :sub="$money($reimbursements['approved_amount']).' approved this month'" />
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <x-pulse.card class="xl:col-span-2" :title="'Payroll — '.$period->format('F Y')" icon="banknotes" flush>
                @if($runs->isEmpty())
                    <div class="px-5 pb-5 text-sm text-zinc-500">No payroll has been run for {{ $period->format('F Y') }} yet.</div>
                @else
                    <table class="w-full text-sm">
                        <thead class="border-y border-zinc-100 bg-zinc-50 text-left text-xs text-zinc-500 dark:border-white/5 dark:bg-white/5">
                            <tr>
                                <th class="px-5 py-2 font-medium">Cycle</th>
                                <th class="px-5 py-2 font-medium">Status</th>
                                <th class="px-5 py-2 text-right font-medium">Payslips</th>
                                <th class="px-5 py-2 text-right font-medium">Payout</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach($runs as $run)
                                <tr>
                                    <td class="px-5 py-2.5 font-medium text-zinc-900 dark:text-white">{{ $cycleLabel($run->cycle) }}</td>
                                    <td class="px-5 py-2.5"><flux:badge size="sm" :color="$statusColor($run->status)">{{ $statusLabel($run->status) }}</flux:badge></td>
                                    <td class="px-5 py-2.5 text-right tabular-nums">{{ $run->payslips_count }}</td>
                                    <td class="px-5 py-2.5 text-right font-semibold tabular-nums">{{ $money($run->total_payout) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-pulse.card>

            <x-pulse.card title="Net pay (finalised)" icon="chart-bar">
                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between"><dt class="text-zinc-500">{{ $period->format('F') }}</dt><dd class="font-semibold tabular-nums">{{ $money($netThisMonth) }}</dd></div>
                    <div class="flex items-center justify-between"><dt class="text-zinc-500">Previous month</dt><dd class="font-semibold tabular-nums">{{ $money($netLastMonth) }}</dd></div>
                    <div class="flex items-center justify-between border-t border-zinc-100 pt-3 dark:border-white/5"><dt class="text-zinc-500">{{ $period->year }} to date</dt><dd class="font-bold tabular-nums">{{ $money($netYearToDate) }}</dd></div>
                </dl>
            </x-pulse.card>
        </div>

        <div class="grid grid-cols-1 gap-5 xl:grid-cols-3">
            <x-pulse.card title="Awaiting finance sign-off" icon="check-badge" flush>
                @forelse($awaitingFinance as $run)
                    <div class="flex items-center justify-between gap-3 border-t border-zinc-100 px-5 py-2.5 text-sm first:border-t-0 dark:border-white/5">
                        <span>{{ $run->month }} {{ $run->year }} · {{ $cycleLabel($run->cycle) }}</span>
                        <span class="font-semibold tabular-nums">{{ $money($run->total_payout) }}</span>
                    </div>
                @empty
                    <div class="px-5 pb-5 text-sm text-zinc-500">Nothing is waiting for finance approval.</div>
                @endforelse
                @if($canApproveFinance && $awaitingFinance->isNotEmpty())
                    <div class="px-5 py-3"><flux:button size="sm" :href="route('payroll.finance-approve')" wire:navigate>Review sign-offs</flux:button></div>
                @endif
            </x-pulse.card>

            <x-pulse.card title="Largest unpaid overtime" icon="clock" flush>
                @forelse($otPayable['top'] as $row)
                    <div class="flex items-center justify-between gap-3 border-t border-zinc-100 px-5 py-2.5 text-sm first:border-t-0 dark:border-white/5">
                        <span class="truncate">{{ $row->employee?->user?->name ?? '—' }}</span>
                        <span class="shrink-0 tabular-nums text-zinc-500">{{ round((float) $row->hours, 2) }} h · <span class="font-semibold text-zinc-900 dark:text-white">{{ $money($row->amount) }}</span></span>
                    </div>
                @empty
                    <div class="px-5 pb-5 text-sm text-zinc-500">No approved overtime is waiting to be paid.</div>
                @endforelse
            </x-pulse.card>

            <x-pulse.card title="Compensation decisions" icon="arrow-trending-up">
                <div class="space-y-3 text-sm">
                    <div class="flex items-center justify-between">
                        <span class="text-zinc-500">Leave encashments at finance</span>
                        @if($encashmentsAwaitingFinance > 0)
                            <a href="{{ route('time-off.encashments') }}" wire:navigate class="font-semibold text-brand-600 hover:underline">{{ $encashmentsAwaitingFinance }}</a>
                        @else
                            <span class="font-semibold">0</span>
                        @endif
                    </div>
                    @forelse($incrementCycles as $cycle)
                        <div class="flex items-center justify-between">
                            <span class="text-zinc-500">Increments FY {{ $cycle->financial_year }} ({{ $cycle->proposals_count }})</span>
                            <span class="font-semibold tabular-nums">{{ $money($cycle->proposed_total) }}</span>
                        </div>
                    @empty
                        <div class="text-zinc-500">No increment cycle is waiting for finance approval.</div>
                    @endforelse
                    @if($heldIncrements > 0)
                        <div class="text-xs text-amber-600">{{ $heldIncrements }} increment {{ \Illuminate\Support\Str::plural('proposal', $heldIncrements) }} held for a second approver.</div>
                    @endif
                </div>
            </x-pulse.card>
        </div>
    </div>
</flux:main>
