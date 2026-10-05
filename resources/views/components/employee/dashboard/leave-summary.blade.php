@props(['leave'])

@php
    $fmt = fn ($n) => \App\Services\EmployeeDashboardService::formatDays($n);
    $signed = fn ($n) => ($n > 0 ? '+' : '').$fmt($n);
    $p = $leave['primary'];

    // CSL bucket by bucket: accrued this year + carry forward ± adjustments −
    // expired − used − encashed = approved balance; less pending = available
    // to request. Zero rows are noise, except accrued, carry forward and used.
    $rows = $p ? array_values(array_filter([
        ['Accrued this year', $p['credit'], false],
        ['Carry forward', $p['carry_forward'], false],
        ['Add-ons / adjustments', $p['adjustments'], true],
        ['Expired', -$p['expired'], true],
        ['Used', -$p['used'], true],
        ['Encashed', -$p['encashed'], true],
        ['Approved balance', $p['available'], false],
        ['Pending', -$p['pending'], true],
    ], fn ($r) => in_array($r[0], ['Accrued this year', 'Carry forward', 'Used', 'Approved balance'], true) || (float) $r[1] != 0.0)) : [];

    $overdrawn = $p && $p['available_to_request'] < 0;
@endphp

{{-- LeaveSummary — the employee's current leave-year balance, read through
     LeaveBalanceCalculator (the figure My Time Off and HR's Employee Leave
     Detail show). Never floored: an overdrawn balance reads as negative. --}}
<x-employee.dashboard.card title="Leave Summary" icon="calendar-days" :subtitle="'Leave year '.$leave['year_label']" {{ $attributes }}>
    @if(! $p)
        <x-employee.dashboard.empty-state icon="calendar-days" title="No leave balance yet"
            :text="'HR has not allocated leave for '.$leave['year_label'].' yet. Contact HR if this looks wrong.'">
            <a href="{{ route('time-off.my') }}" wire:navigate class="mt-2 text-xs font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400">Open My Time Off</a>
        </x-employee.dashboard.empty-state>
    @else
        <div>
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                {{ $p['name'] }} <span class="text-zinc-400">· policy {{ $fmt($leave['policy']['csl_days']) }} days/year + {{ $leave['policy']['mdl_days'] }} MDL</span>
            </p>
            <p class="mt-1 flex items-baseline gap-1.5">
                <span @class(['text-3xl font-semibold tabular-nums tracking-tight', 'text-rose-600 dark:text-rose-400' => $overdrawn, 'text-zinc-900 dark:text-white' => ! $overdrawn])>{{ $fmt($p['available_to_request']) }}</span>
                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ abs($p['available_to_request']) == 1 ? 'day' : 'days' }} available to request</span>
                @if($overdrawn)
                    <span class="ms-1 rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700 ring-1 ring-inset ring-rose-600/15 dark:bg-rose-500/10 dark:text-rose-300">Overdrawn</span>
                @endif
            </p>
            @if($p['pending'] > 0)
                <p class="mt-1 text-xs text-amber-700 dark:text-amber-400">
                    {{ $fmt($p['pending']) }} {{ $p['pending'] == 1 ? 'day' : 'days' }} awaiting approval
                </p>
            @endif
        </div>

        <dl class="mt-4 divide-y divide-zinc-100 text-sm dark:divide-white/[0.06]">
            @foreach($rows as [$label, $value, $isMovement])
                <div class="flex items-center justify-between py-1.5">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ $label }}</dt>
                    <dd class="tabular-nums text-zinc-800 dark:text-zinc-200">{{ $isMovement ? $signed($value) : $fmt($value) }}</dd>
                </div>
            @endforeach
            <div class="flex items-center justify-between pt-2.5">
                <dt class="font-medium text-zinc-900 dark:text-white">Available to request</dt>
                <dd @class(['font-semibold tabular-nums', 'text-rose-600 dark:text-rose-400' => $overdrawn, 'text-zinc-900 dark:text-white' => ! $overdrawn])>{{ $fmt($p['available_to_request']) }}</dd>
            </div>
        </dl>

        @if(! empty($leave['mdl']['dates']))
            {{-- Spec §5.4: "MDL days this December" — company shutdown days,
                 not a balance; working one earns 1 Comp Off. --}}
            <div class="mt-4 rounded-lg bg-indigo-50/60 px-3 py-2 dark:bg-indigo-500/[0.06]">
                <p class="text-[11px] font-medium uppercase tracking-wide text-indigo-500 dark:text-indigo-300">MDL shutdown days</p>
                <p class="mt-0.5 text-sm text-zinc-700 dark:text-zinc-200">
                    {{ collect($leave['mdl']['dates'])->map(fn ($d) => $d['date']->format('j M'))->implode(', ') }}
                </p>
                <p class="mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">Not deducted from your leave · working one earns 1 Comp Off</p>
            </div>
        @endif

        @if($leave['others']->isNotEmpty())
            {{-- Capped so an employee with a dozen leave types does not stretch
                 the card (and the row beside it); the full list is a click away. --}}
            @php $shownOthers = $leave['others']->take(6); $hiddenOthers = $leave['others']->count() - $shownOthers->count(); @endphp
            <div class="mt-4 rounded-lg bg-zinc-50 px-3 py-2 dark:bg-white/[0.03]">
                <p class="text-[11px] font-medium uppercase tracking-wide text-zinc-400">Other leave</p>
                <ul class="mt-1 grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2 md:grid-cols-1 2xl:grid-cols-2">
                    @foreach($shownOthers as $other)
                        <li class="flex min-w-0 items-center justify-between gap-2">
                            <span class="truncate text-zinc-600 dark:text-zinc-300" title="{{ $other['name'] }}">{{ $other['name'] }}</span>
                            <span @class(['shrink-0 tabular-nums', 'text-rose-600' => $other['available'] < 0, 'text-zinc-900 dark:text-white' => $other['available'] >= 0])>{{ $fmt($other['available']) }}<span class="text-xs text-zinc-400"> {{ abs($other['available']) == 1 ? 'day' : 'days' }}</span></span>
                        </li>
                    @endforeach
                </ul>
                @if($hiddenOthers > 0)
                    <a href="{{ route('time-off.my') }}" wire:navigate class="mt-1.5 inline-flex text-xs font-medium text-orange-600 hover:text-orange-700 dark:text-orange-400">
                        +{{ $hiddenOthers }} more {{ \Illuminate\Support\Str::plural('type', $hiddenOthers) }} in My Time Off
                    </a>
                @endif
            </div>
        @endif

        <div class="mt-auto flex items-center gap-3 pt-5">
            <a href="{{ route('time-off.my') }}" wire:navigate
               class="inline-flex flex-1 items-center justify-center gap-1.5 rounded-lg bg-orange-500 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:bg-orange-600 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-orange-500">
                <flux:icon.plus class="size-4" /> Apply Leave
            </a>
            <a href="{{ route('time-off.my') }}" wire:navigate
               class="inline-flex items-center gap-1 text-sm font-medium text-zinc-600 transition hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-white">
                Leave history <flux:icon.arrow-right class="size-3.5" />
            </a>
        </div>
    @endif
</x-employee.dashboard.card>
