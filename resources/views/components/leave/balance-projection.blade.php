{{--
    The balance a paid request draws on at its own dates (LeaveBalanceProjection) —
    the same figures in the employee's request drawer and on HR's request page.
--}}
@props(['projection', 'paid' => 0.0, 'lwp' => 0.0, 'title' => null, 'bookingsTitle' => 'Other approved and pending requests', 'showLines' => true, 'showBookings' => true])

@php
    $p = $projection;
    $fmt = fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    $on = \Illuminate\Support\Carbon::parse(max($p['start'], $p['today']));
    $onLabel = $on->toImmutable()->userDate();
    $available = round($p['available_at_start'] - $p['kept_for_later'], 2);
    $remaining = round($available - (float) $paid, 2);
    $months = collect($p['expected_months'])->map(fn (string $m) => \Illuminate\Support\Carbon::parse($m.'-01')->format('M Y'))->implode(', ');
    $row = 'flex items-baseline justify-between gap-3';
    $muted = 'text-zinc-600 dark:text-zinc-300';
@endphp

<div {{ $attributes->merge(['class' => 'space-y-3 text-sm']) }} data-balance-projection>
    @if($title)
        <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $title }}</h3>
    @endif

    @if($showLines)
    <dl class="space-y-1.5">
        <div class="{{ $row }}" data-line="balance-today">
            <dt class="{{ $muted }}">{{ $p['balance_type'] }} balance today
                @if($p['booked_ahead'] > 0)<span class="block text-xs text-zinc-500 dark:text-zinc-400">before {{ $fmt($p['booked_ahead']) }} {{ \Illuminate\Support\Str::plural('day', $p['booked_ahead'] == 1 ? 1 : 2) }} already approved for later dates</span>@endif
            </dt>
            <dd class="tabular-nums font-medium text-zinc-900 dark:text-white">{{ $fmt($p['balance_today']) }}</dd>
        </div>
        <div class="{{ $row }}" data-line="expected-accrual">
            <dt class="{{ $muted }}">Expected monthly accrual by {{ $onLabel }}
                <span class="block text-xs text-zinc-500 dark:text-zinc-400">{{ $months !== '' ? $months : 'None — only months completed before the leave starts count' }}</span>
            </dt>
            <dd class="tabular-nums font-medium text-emerald-700 dark:text-emerald-400">+{{ $fmt($p['expected_accrual']) }}</dd>
        </div>
        @if($p['booked_by_start'] > 0)
            <div class="{{ $row }}" data-line="booked">
                <dt class="{{ $muted }}">Already booked on or before {{ $onLabel }}</dt>
                <dd class="tabular-nums font-medium text-rose-600 dark:text-rose-400">−{{ $fmt($p['booked_by_start']) }}</dd>
            </div>
        @endif
        @if($p['kept_for_later'] > 0)
            <div class="{{ $row }}" data-line="kept">
                <dt class="{{ $muted }}">Kept for later leave{{ $p['limited_by'] ? ' on '.\Illuminate\Support\Carbon::parse($p['limited_by']['start_date'])->toImmutable()->userDate() : '' }}</dt>
                <dd class="tabular-nums font-medium text-rose-600 dark:text-rose-400">−{{ $fmt($p['kept_for_later']) }}</dd>
            </div>
        @endif
        <div class="{{ $row }} border-t border-zinc-200 pt-1.5 dark:border-white/10" data-line="available">
            <dt class="font-medium text-zinc-900 dark:text-white">{{ $p['balance_type'] }} available before this request</dt>
            <dd class="tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $fmt($available) }}</dd>
        </div>
        @if($p['negative_limit'] > 0)
            <div class="{{ $row }}"><dt class="{{ $muted }}">Policy allows going below zero by</dt><dd class="tabular-nums">{{ $fmt($p['negative_limit']) }}</dd></div>
        @endif
        <div class="{{ $row }}" data-line="this-request">
            <dt class="{{ $muted }}">This request (paid)</dt>
            <dd class="tabular-nums font-medium text-rose-600 dark:text-rose-400">{{ $paid > 0 ? '−'.$fmt($paid) : '0' }}</dd>
        </div>
        @if($lwp > 0)
            <div class="{{ $row }}" data-line="lwp">
                <dt class="{{ $muted }}">Leave Without Pay (no balance used)</dt>
                <dd class="tabular-nums font-medium text-amber-700 dark:text-amber-300">{{ $fmt($lwp) }}</dd>
            </div>
        @endif
        <div class="{{ $row }} border-t border-zinc-200 pt-1.5 dark:border-white/10" data-line="remaining">
            <dt class="font-semibold text-zinc-900 dark:text-white">Remaining after approval</dt>
            <dd @class(['tabular-nums font-bold', 'text-rose-600 dark:text-rose-400' => $remaining < 0, 'text-zinc-900 dark:text-white' => $remaining >= 0])>{{ $fmt($remaining) }}</dd>
        </div>
    </dl>

    @if($p['expected_accrual'] > 0)
        <p class="text-xs text-zinc-500 dark:text-zinc-400">The paid days are deducted once, on approval. Until the expected accrual is credited (on the 1st of each following month), today's balance can show below zero by those days.</p>
    @endif
    @if($p['accrual_note'])
        <p class="text-xs text-amber-700 dark:text-amber-300" data-accrual-note>{{ $p['accrual_note'] }}</p>
    @endif
    @endif

    @if($showBookings)
        <div data-bookings>
            <p class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $bookingsTitle }} · {{ $p['year'] }}</p>
            @if($p['bookings'] === [])
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">None — nothing else is drawing on {{ $p['balance_type'] }}.</p>
            @else
                <ul class="mt-1.5 divide-y divide-zinc-100 rounded-lg border border-zinc-200 dark:divide-white/5 dark:border-white/10">
                    @foreach($p['bookings'] as $b)
                        @php [$statusLabel, $statusColor] = \App\Services\Leave\LeaveRequestPresenter::status($b['status']); @endphp
                        <li class="flex items-center justify-between gap-3 px-3 py-1.5" data-booking="{{ $b['reference'] }}">
                            <span class="min-w-0">
                                <span class="block truncate text-xs font-medium text-zinc-900 dark:text-white">{{ $b['reference'] }} · {{ \Illuminate\Support\Carbon::parse($b['start_date'])->toImmutable()->userDate() }}{{ $b['end_date'] !== $b['start_date'] ? ' – '.\Illuminate\Support\Carbon::parse($b['end_date'])->toImmutable()->userDate() : '' }}</span>
                                <span class="block truncate text-xs text-zinc-500 dark:text-zinc-400">{{ $b['reason'] ?? 'Leave' }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <x-pulse.badge :color="$statusColor">{{ $statusLabel }}</x-pulse.badge>
                                <span class="text-xs font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($b['paid_days']) }} paid</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
