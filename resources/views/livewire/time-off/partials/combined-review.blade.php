{{-- Combined Leave review: the whole request, its totals, the balance before
     and after, payroll impact and the date-wise allocation. --}}
@php use App\Models\LeaveRequestDay; @endphp

<div class="space-y-4" data-review data-combined-review>
    <div class="rounded-xl border border-zinc-200 dark:border-white/10">
        <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-2.5 dark:border-zinc-800">
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Leave details</h3>
            <button type="button" wire:click="editStep(2)" class="inline-flex items-center gap-1 text-xs font-semibold text-orange-600 hover:underline"><flux:icon.pencil-square class="size-3.5" /> Edit</button>
        </div>
        <dl class="divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
            @foreach([
                'Request type' => 'Combined Leave',
                'Reason type' => $ceval['reason'],
                'Date range' => $date($startDate).' – '.$date($endDate),
                'Working days' => $ceval['working_days'],
                'Paid' => $fmt($ceval['paid_units']).' '.\Illuminate\Support\Str::plural('day', $ceval['paid_units']),
                'Unpaid' => $fmt($ceval['unpaid_units']).' '.\Illuminate\Support\Str::plural('day', $ceval['unpaid_units']),
                'Half-day' => $ceval['half_day_count'] ? $ceval['half_day_count'].' ('.$fmt($ceval['half_day_units']).' day)' : 'None',
                'Excluded' => $ceval['excluded_days'].' '.\Illuminate\Support\Str::plural('date', $ceval['excluded_days']),
                'Reason' => $reason,
                'Attachment' => $attachment?->getClientOriginalName() ?? 'None',
            ] as $k => $v)
                <div class="flex gap-4 px-4 py-2"><dt class="w-28 shrink-0 text-zinc-500">{{ $k }}</dt><dd class="min-w-0 flex-1 break-words text-zinc-900 dark:text-white">{{ $v }}</dd></div>
            @endforeach
        </dl>
    </div>

    @if($ceval['balance_type'])
        <div class="rounded-xl border border-orange-100 bg-orange-50/60 p-4 text-sm dark:border-orange-500/20 dark:bg-orange-500/5" data-balance-impact>
            <h3 class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">Balance impact (after approval)</h3>
            <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-300">{{ $ceval['balance_type'] }} balance before</span><span class="tabular-nums">{{ $fmt($ceval['available']) }} {{ \Illuminate\Support\Str::plural('day', $ceval['available']) }}</span></div>
            <div class="flex justify-between"><span class="text-zinc-600 dark:text-zinc-300">Reserved by this request</span><span class="tabular-nums text-rose-600">−{{ $fmt($ceval['paid_units']) }} {{ \Illuminate\Support\Str::plural('day', $ceval['paid_units']) }}</span></div>
            <div class="mt-1 flex justify-between border-t border-orange-100 pt-1 font-semibold dark:border-orange-500/20"><span>Balance after approval</span><span class="tabular-nums">{{ $fmt($ceval['remaining_after']) }} {{ \Illuminate\Support\Str::plural('day', $ceval['remaining_after']) }}</span></div>
        </div>
    @endif

    <div class="rounded-xl bg-zinc-50 px-4 py-3 text-sm dark:bg-white/5" data-payroll-impact>
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Payroll impact</p>
        <p class="mt-1 text-zinc-900 dark:text-white">{{ $ceval['unpaid_units'] > 0 ? $fmt($ceval['unpaid_units']).' unpaid '.\Illuminate\Support\Str::plural('day', $ceval['unpaid_units']).' (Leave Without Pay)' : 'None — all paid' }}</p>
    </div>

    <div class="rounded-xl border border-zinc-200 dark:border-white/10" data-review-dates>
        <p class="border-b border-zinc-100 px-4 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:border-zinc-800">By date</p>
        <ul class="divide-y divide-zinc-100 text-xs dark:divide-zinc-800">
            @foreach($ceval['rows'] as $row)
                <li class="flex justify-between gap-3 px-4 py-1.5 {{ $row['chargeable'] ? '' : 'text-zinc-400' }}">
                    <span>{{ \Illuminate\Support\Carbon::parse($row['date'])->toImmutable()->userShortDate() }}</span>
                    <span>{{ $row['chargeable'] ? LeaveRequestDay::LABELS[$row['allocation']].($row['session'] ? ' · '.($row['session'] === 'first_half' ? 'first half' : 'second half') : '') : $row['label'] }}</span>
                </li>
            @endforeach
        </ul>
    </div>

    <div class="rounded-xl bg-zinc-50 px-4 py-3 text-sm dark:bg-white/5" data-approval-path>
        <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Approval path</p>
        <p class="mt-1 text-zinc-900 dark:text-white">{{ collect($ceval['approval_steps'])->map(fn ($s) => ['manager' => 'Reporting Manager', 'hr' => 'HR'][$s] ?? ucfirst($s))->implode(' → ') }}</p>
    </div>
</div>
