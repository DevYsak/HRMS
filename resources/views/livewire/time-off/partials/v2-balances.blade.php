{{-- Leave V2 balances: ONE Earned Leave balance (with usage by reason —
     statistics, not balances), Comp Off, Unpaid Leave, Special Leave where
     separately entitled, and other (historical) paid balances shown apart.
     Every figure is LeaveBalanceCalculator's for the balance row itself. --}}
@if($this->v2Balances === null)
    <p class="rounded-xl border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500 dark:border-white/10">You do not have an employee profile yet. Contact HR.</p>
@else
@php
    $b = $this->v2Balances;
    $earned = $b['earned'];
    $compOff = $b['comp_off'];
    $pct = fn ($part, $whole) => $whole > 0 ? max(0, min(100, round($part / $whole * 100))) : 0;
    $card = 'rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900';
@endphp

<div class="space-y-4" data-v2-balances>
    <p class="text-xs text-zinc-500">
        Policy: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $b['policy'] ?? 'No policy assigned' }}</span>
        <span class="text-zinc-400">· {{ $b['policy_source'] }}</span>
    </p>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-3">
        {{-- Earned Leave: the one shared paid balance --}}
        <div class="{{ $card }}" data-category-card="earned">
            <div class="flex items-center gap-2">
                <span class="flex size-8 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600 dark:bg-emerald-500/10 dark:text-emerald-400"><flux:icon.sparkles class="size-4" /></span>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Earned Leave</p>
            </div>
            @if($earned === null)
                <p class="mt-3 text-sm text-zinc-500">Earned Leave is not set up for your policy yet. Contact HR.</p>
            @else
                <p class="mt-3 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $earned['unlimited'] ? '—' : $fmt($earned['available']) }}</p>
                <p class="text-xs text-zinc-500">Days available to request</p>
                <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-emerald-500" style="width: {{ $pct($earned['used'], $earned['credits']) }}%"></div></div>
                <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                    <div><dt class="text-zinc-500">Accrued</dt><dd class="font-semibold tabular-nums">{{ $fmt($earned['credits']) }}</dd></div>
                    <div><dt class="text-zinc-500">Used</dt><dd class="font-semibold tabular-nums">{{ $fmt($earned['used']) }}</dd></div>
                    <div><dt class="text-zinc-500">Pending</dt><dd class="font-semibold tabular-nums">{{ $fmt($earned['pending']) }}</dd></div>
                </dl>
            @endif
        </div>

        {{-- Comp Off --}}
        <div class="{{ $card }}" data-category-card="comp_off">
            <div class="flex items-center gap-2">
                <span class="flex size-8 items-center justify-center rounded-lg bg-violet-50 text-violet-600 dark:bg-violet-500/10 dark:text-violet-400"><flux:icon.arrow-path-rounded-square class="size-4" /></span>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Comp Off</p>
            </div>
            <p class="mt-3 text-3xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($compOff['available']) }}</p>
            <p class="text-xs text-zinc-500">Days available · separate from Earned Leave</p>
            <div class="mt-3 h-1.5 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10"><div class="h-full rounded-full bg-violet-500" style="width: {{ $pct($compOff['used'], $compOff['credits']) }}%"></div></div>
            <dl class="mt-2 grid grid-cols-3 gap-2 text-xs">
                <div><dt class="text-zinc-500">Earned</dt><dd class="font-semibold tabular-nums">{{ $fmt($compOff['credits']) }}</dd></div>
                <div><dt class="text-zinc-500">Used</dt><dd class="font-semibold tabular-nums">{{ $fmt($compOff['used']) }}</dd></div>
                <div><dt class="text-zinc-500">Pending</dt><dd class="font-semibold tabular-nums">{{ $fmt($compOff['pending']) }}</dd></div>
            </dl>
        </div>

        {{-- Unpaid Leave --}}
        <div class="{{ $card }}" data-category-card="unpaid">
            <div class="flex items-center gap-2">
                <span class="flex size-8 items-center justify-center rounded-lg bg-rose-50 text-rose-600 dark:bg-rose-500/10 dark:text-rose-400"><flux:icon.banknotes class="size-4" /></span>
                <p class="text-sm font-semibold text-zinc-900 dark:text-white">Unpaid Leave</p>
            </div>
            <p class="mt-3 text-3xl font-bold text-zinc-900 dark:text-white" aria-label="No balance required">∞</p>
            <p class="text-xs text-zinc-500">No balance required · affects payroll</p>
            <dl class="mt-5 grid grid-cols-2 gap-2 text-xs">
                <div><dt class="text-zinc-500">Used this year</dt><dd class="font-semibold tabular-nums">{{ $fmt($b['unpaid']['used']) }}</dd></div>
                <div><dt class="text-zinc-500">Pending</dt><dd class="font-semibold tabular-nums">{{ $fmt($b['unpaid']['pending']) }}</dd></div>
            </dl>
        </div>
    </div>

    {{-- Earned Leave in detail, and usage by reason (statistics, not balances) --}}
    @if($earned !== null)
        <div class="{{ $card }}" data-earned-detail>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Earned Leave</h3>
                <div class="flex flex-wrap gap-2">
                    <flux:button size="xs" variant="primary" wire:click="apply({{ $earned['id'] }})">Request</flux:button>
                    @if($earned['has_balance'])<flux:button size="xs" wire:click="showStatement({{ $earned['id'] }})">View details</flux:button>@endif
                </div>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-sm sm:grid-cols-5">
                @foreach([
                    'Annual entitlement' => $earned['annual_entitlement'] ?? ($b['annual_cap'] ?? null),
                    'Accrued' => $earned['credits'],
                    'Used' => $earned['used'],
                    'Pending' => $earned['pending'],
                    'Available' => $earned['available'],
                ] as $label => $value)
                    <div class="rounded-xl bg-zinc-50 px-3 py-2 dark:bg-white/5">
                        <dt class="text-xs text-zinc-500">{{ $label }}</dt>
                        <dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $value === null ? '—' : $fmt($value) }}</dd>
                    </div>
                @endforeach
            </dl>

            <div class="mt-4" data-usage-by-reason>
                <h4 class="text-xs font-semibold uppercase tracking-wide text-zinc-500">Usage by reason</h4>
                @if($earned['usage_by_reason'] === [])
                    <p class="mt-1 text-sm text-zinc-500">No Earned Leave taken this year.</p>
                @else
                    <ul class="mt-1 divide-y divide-zinc-100 text-sm dark:divide-white/5">
                        @foreach($earned['usage_by_reason'] as $reason)
                            <li wire:key="v2-reason-{{ $reason['id'] }}" class="flex items-center justify-between gap-3 py-1.5" data-reason-usage="{{ $reason['code'] }}">
                                <span class="text-zinc-700 dark:text-zinc-200">{{ $reason['name'] }}</span>
                                <span class="tabular-nums text-zinc-900 dark:text-white">{{ $fmt($reason['used']) }} used
                                    @if($reason['pending'] > 0)<span class="text-zinc-500">· {{ $fmt($reason['pending']) }} pending</span>@endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
                <p class="mt-1 text-[11px] text-zinc-400">These are usage figures only. Every reason draws on the one Earned Leave balance.</p>
            </div>
        </div>
    @endif

    {{-- Special / statutory leave with its own entitlement: kept apart from Earned Leave. --}}
    @if($b['special']['subtypes'] !== [])
        <div class="{{ $card }}" data-special-leave>
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Special Leave</h3>
            <p class="mt-0.5 text-xs text-zinc-500">Statutory and special leave with its own entitlement. Not part of your Earned Leave.</p>
            <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($b['special']['subtypes'] as $sub)
                    <div wire:key="v2-special-{{ $sub['id'] }}" class="rounded-xl border border-zinc-100 p-4 dark:border-white/5" data-special-card="{{ $sub['code'] }}">
                        <p class="truncate text-sm font-medium text-zinc-700 dark:text-zinc-200">{{ $sub['name'] }}</p>
                        <p class="mt-1 text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $fmt($sub['available']) }}
                            @if($sub['annual_entitlement'] !== null)<span class="text-xs font-medium text-zinc-400">/ {{ $fmt($sub['annual_entitlement']) }} days</span>@endif
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <flux:button size="xs" variant="primary" wire:click="apply({{ $sub['id'] }})">Request</flux:button>
                            @if($sub['has_balance'])<flux:button size="xs" wire:click="showStatement({{ $sub['id'] }})">View details</flux:button>@endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    @if($b['historical_balances'] > 0)
        <p class="text-xs text-zinc-500" data-historical-balances>{{ $b['historical_balances'] }} earlier {{ \Illuminate\Support\Str::plural('balance', $b['historical_balances']) }} from before this policy {{ $b['historical_balances'] === 1 ? 'is' : 'are' }} kept in your leave history. {{ $b['historical_balances'] === 1 ? 'It is' : 'They are' }} not part of your Earned Leave and cannot be used for new requests.</p>
    @endif

    {{-- Paid types still active but not in a V2 category: visible, never added to Earned Leave. --}}
    @if($b['other_paid'] !== [])
        <div class="{{ $card }}" data-other-paid>
            <h3 class="text-sm font-semibold text-zinc-900 dark:text-white">Other paid leave</h3>
            <p class="mt-0.5 text-xs text-zinc-500">Other paid leave types you hold. They are not part of your Earned Leave.</p>
            <div class="mt-3 divide-y divide-zinc-100 dark:divide-white/5">
                @foreach($b['other_paid'] as $other)
                    <div wire:key="v2-other-{{ $other['id'] }}" class="flex items-center justify-between gap-3 py-2 text-sm">
                        <span class="min-w-0 truncate text-zinc-700 dark:text-zinc-200">{{ $other['name'] }}@if($other['code']) <span class="text-zinc-400">({{ $other['code'] }})</span>@endif</span>
                        <span class="flex shrink-0 items-center gap-3">
                            <span class="tabular-nums font-semibold text-zinc-900 dark:text-white">{{ $fmt($other['available']) }} days</span>
                            <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $other['id'] }})">View details</flux:button>
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
@endif
