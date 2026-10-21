{{-- Combined Leave: one row per date of the range. Excluded dates (weekly
     off, holiday, shutdown) are shown but not charged; each chargeable date is
     Full or Half Day, Paid or Unpaid. Totals and the balance update as rows
     change. Display only — every value comes from CombinedLeaveService. --}}
@php
    use App\Models\LeaveRequestDay;

    $rules = $ceval['rules'];
    $options = array_filter([
        LeaveRequestDay::FULL_PAID => true,
        LeaveRequestDay::FULL_UNPAID => true,
        LeaveRequestDay::HALF_PAID => $rules['allow_half_days'] && $rules['allow_half_paid'],
        LeaveRequestDay::HALF_UNPAID => $rules['allow_half_days'] && $rules['allow_half_unpaid'],
    ]);
    $cell = 'w-full rounded-md border border-zinc-200 bg-white px-2 py-1.5 text-xs text-zinc-900 focus:border-orange-400 focus:outline-none dark:border-white/10 dark:bg-zinc-900 dark:text-white';
    // One short badge per status; detail (a holiday's name) goes underneath, muted.
    $badges = [
        LeaveRequestDay::STATUS_WORKING => ['Working Day', 'emerald'],
        LeaveRequestDay::STATUS_HOLIDAY => ['Holiday', 'rose'],
        LeaveRequestDay::STATUS_SHUTDOWN => ['Shutdown', 'amber'],
        LeaveRequestDay::STATUS_WEEKLY_OFF => ['Weekly Off', 'zinc'],
    ];
    $detail = fn (array $row) => $row['status'] === LeaveRequestDay::STATUS_HOLIDAY ? $row['label'] : null;
    $tooltip = fn (array $row) => match ($row['status']) {
        LeaveRequestDay::STATUS_SHUTDOWN => 'Company shutdown',
        LeaveRequestDay::STATUS_HOLIDAY => $row['label'],
        default => $row['chargeable'] ? 'Working day' : $row['label'],
    };
@endphp

<div class="space-y-4" data-combined>
    {{-- Desktop: a fixed-layout table; only the rows scroll, the header stays. --}}
    {{-- Fixed widths and the scroll height are inline so they hold without a CSS rebuild. --}}
    <div class="hidden overflow-y-auto rounded-xl border border-zinc-200 sm:block dark:border-white/10" style="max-height: 24rem" data-combined-scroll>
        <table class="w-full text-left text-xs" style="table-layout: fixed" data-combined-rows>
            <colgroup>
                <col style="width: 76px"><col style="width: 52px"><col style="width: 120px"><col>
            </colgroup>
            <thead class="text-[10px] font-bold uppercase tracking-wide text-zinc-600 dark:text-zinc-300">
                <tr>
                    <th class="sticky top-0 z-10 bg-zinc-100 px-3 py-2 dark:bg-zinc-800">Date</th>
                    <th class="sticky top-0 z-10 bg-zinc-100 px-2 py-2 dark:bg-zinc-800">Day</th>
                    <th class="sticky top-0 z-10 bg-zinc-100 px-2 py-2 dark:bg-zinc-800">Status</th>
                    <th class="sticky top-0 z-10 bg-zinc-100 px-3 py-2 dark:bg-zinc-800">Allocation</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($ceval['rows'] as $row)
                    @php [$badge, $tone] = $badges[$row['status']] ?? ['Working Day', 'emerald']; @endphp
                    <tr wire:key="cday-{{ $row['date'] }}" data-combined-date="{{ $row['date'] }}" data-day-status="{{ $row['status'] }}"
                        @class(['bg-zinc-50/70 dark:bg-white/[0.02]' => ! $row['chargeable']])>
                        <td style="height: 56px" @class(['whitespace-nowrap px-3 py-2 align-middle font-medium', 'text-zinc-900 dark:text-white' => $row['chargeable'], 'text-zinc-400' => ! $row['chargeable']])>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M') }}</td>
                        <td @class(['whitespace-nowrap px-2 py-2 align-middle', 'text-zinc-600 dark:text-zinc-300' => $row['chargeable'], 'text-zinc-400' => ! $row['chargeable']])>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('D') }}</td>
                        <td class="px-2 py-2 align-middle" title="{{ $tooltip($row) }}">
                            <x-pulse.badge :color="$tone" data-status-badge>{{ $badge }}</x-pulse.badge>
                            @if($detail($row))
                                <span class="mt-0.5 block truncate text-[11px] text-zinc-500 dark:text-zinc-400" data-status-detail>{{ $detail($row) }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-middle">
                            @if($row['chargeable'])
                                <div class="space-y-1.5">
                                    <select wire:model.live="allocations.{{ $row['date'] }}.type" class="{{ $cell }}" aria-label="Allocation for {{ $row['date'] }}" data-allocation-select>
                                        @foreach($options as $value => $on)
                                            <option value="{{ $value }}">{{ LeaveRequestDay::LABELS[$value] }}</option>
                                        @endforeach
                                    </select>
                                    @if(LeaveRequestDay::isHalf($row['allocation']) && $rules['allow_sessions'])
                                        <select wire:model.live="allocations.{{ $row['date'] }}.session" class="{{ $cell }}" aria-label="Half for {{ $row['date'] }}">
                                            <option value="first_half">First Half</option>
                                            <option value="second_half">Second Half</option>
                                        </select>
                                    @endif
                                </div>
                            @else
                                <span class="whitespace-nowrap text-[11px] text-zinc-400 dark:text-zinc-500" data-excluded>No leave charged</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Phone: one card per date — no squeezed four-column table. --}}
    <ul class="space-y-2 sm:hidden" data-combined-cards>
        @foreach($ceval['rows'] as $row)
            @php [$badge, $tone] = $badges[$row['status']] ?? ['Working Day', 'emerald']; @endphp
            <li wire:key="ccard-{{ $row['date'] }}" @class(['rounded-xl border p-3', 'border-zinc-200 dark:border-white/10' => $row['chargeable'], 'border-zinc-100 bg-zinc-50/70 dark:border-white/5 dark:bg-white/[0.02]' => ! $row['chargeable']])>
                <div class="flex items-center justify-between gap-2">
                    <span @class(['text-sm font-semibold', 'text-zinc-900 dark:text-white' => $row['chargeable'], 'text-zinc-400' => ! $row['chargeable']])>{{ \Illuminate\Support\Carbon::parse($row['date'])->format('d M · D') }}</span>
                    <x-pulse.badge :color="$tone" title="{{ $tooltip($row) }}">{{ $badge }}</x-pulse.badge>
                </div>
                @if($detail($row))
                    <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">{{ $detail($row) }}</p>
                @endif
                @if($row['chargeable'])
                    <div class="mt-2 grid grid-cols-1 gap-2">
                        <label class="text-[11px] font-medium text-zinc-500">Allocation
                            <select wire:model.live="allocations.{{ $row['date'] }}.type" class="{{ $cell }} mt-1">
                                @foreach($options as $value => $on)
                                    <option value="{{ $value }}">{{ LeaveRequestDay::LABELS[$value] }}</option>
                                @endforeach
                            </select>
                        </label>
                        @if(LeaveRequestDay::isHalf($row['allocation']) && $rules['allow_sessions'])
                            <label class="text-[11px] font-medium text-zinc-500">Session
                                <select wire:model.live="allocations.{{ $row['date'] }}.session" class="{{ $cell }} mt-1">
                                    <option value="first_half">First Half</option>
                                    <option value="second_half">Second Half</option>
                                </select>
                            </label>
                        @endif
                    </div>
                @else
                    <p class="mt-1 text-[11px] text-zinc-400 dark:text-zinc-500">No leave charged</p>
                @endif
            </li>
        @endforeach
    </ul>

    {{-- Totals --}}
    <dl class="grid grid-cols-2 gap-2 text-xs sm:grid-cols-4" data-combined-summary>
        @foreach([
            'Calendar days' => $ceval['calendar_days'],
            'Excluded' => $ceval['excluded_days'],
            'Chargeable working days' => $ceval['working_days'],
            'Total charged' => $fmt($ceval['charged_units']).' '.\Illuminate\Support\Str::plural('day', $ceval['charged_units']),
            'Paid leave' => $fmt($ceval['paid_units']).' '.\Illuminate\Support\Str::plural('day', $ceval['paid_units']),
            'Unpaid leave' => $fmt($ceval['unpaid_units']).' '.\Illuminate\Support\Str::plural('day', $ceval['unpaid_units']),
            'Half-day selections' => $ceval['half_day_count'].($ceval['half_day_count'] ? ' ('.$fmt($ceval['half_day_units']).' day)' : ''),
        ] as $k => $v)
            <div class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/5"><dt class="text-zinc-500">{{ $k }}</dt><dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $v }}</dd></div>
        @endforeach
    </dl>

    {{-- Balance: the one the reason charges (Earned Leave for a reason) --}}
    @if($ceval['balance_type'])
        <div class="rounded-xl border border-emerald-100 bg-emerald-50/60 p-4 text-sm dark:border-emerald-500/20 dark:bg-emerald-500/5" data-combined-balance>
            <h3 class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">{{ $ceval['balance_type'] }}</h3>
            @foreach([
                $ceval['balance_type'].' available before request' => $fmt($ceval['approved_available']).' '.\Illuminate\Support\Str::plural('day', $ceval['approved_available']),
                'Pending already reserved' => $fmt($ceval['pending_reserved']).' '.\Illuminate\Support\Str::plural('day', $ceval['pending_reserved']),
                'Available to request before this request' => $fmt($ceval['available']).' '.\Illuminate\Support\Str::plural('day', $ceval['available']),
                'Paid leave used in this request' => '−'.$fmt($ceval['paid_units']).' '.\Illuminate\Support\Str::plural('day', $ceval['paid_units']),
            ] as $k => $v)
                <div class="flex justify-between gap-3"><span class="text-zinc-600 dark:text-zinc-300">{{ $k }}</span><span class="tabular-nums">{{ $v }}</span></div>
            @endforeach
            <div class="mt-1 flex justify-between gap-3 border-t border-emerald-100 pt-1 font-semibold dark:border-emerald-500/20"><span>Remaining after approval</span><span @class(['tabular-nums', 'text-rose-600' => $ceval['remaining_after'] < 0])>{{ $fmt($ceval['remaining_after']) }} {{ \Illuminate\Support\Str::plural('day', $ceval['remaining_after']) }}</span></div>
            @if($ceval['unpaid_units'] > 0)
                <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">{{ $fmt($ceval['unpaid_units']) }} unpaid {{ \Illuminate\Support\Str::plural('day', $ceval['unpaid_units']) }} — Leave Without Pay, deducted from salary.</p>
            @endif
        </div>
    @endif

    @if($ceval['errors'] !== [])
        <div class="rounded-xl border border-rose-200 bg-rose-50 px-3 py-2.5 text-sm text-rose-700 dark:border-rose-500/30 dark:bg-rose-500/10 dark:text-rose-300" role="alert" data-combined-errors>
            <ul class="space-y-1">@foreach($ceval['errors'] as $error)<li>{{ $error }}</li>@endforeach</ul>
            @if($ceval['suggestion'])
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <flux:button size="xs" variant="primary" wire:click="applySuggestion" data-apply-suggestion>Change the excess to unpaid</flux:button>
                    <span class="text-xs">You will see the new allocation before submitting.</span>
                </div>
            @endif
        </div>
    @endif
</div>
