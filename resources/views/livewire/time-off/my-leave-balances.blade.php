<div class="space-y-4">
    @php
        $fmt = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $signed = fn ($v) => ($v > 0 ? '+' : '').$fmt($v);
    @endphp

    {{-- Expiry alerts --}}
    @foreach($this->alerts as $alert)
        <div wire:key="alert-{{ $alert['lot']->id }}" class="flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2.5 text-sm text-amber-900 dark:border-amber-900/40 dark:bg-amber-900/20 dark:text-amber-200">
            <flux:icon.clock class="size-4 shrink-0" />
            <span><strong>{{ $fmt($alert['remaining']) }} {{ ucwords($alert['bucket']) }}</strong> day(s) of {{ $alert['leave_type'] }} expire on <strong>{{ $alert['expires_on']->format('d M Y') }}</strong> — book them before then.</span>
        </div>
    @endforeach

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex gap-1 rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
            @foreach(['balances' => 'My Balances', 'requests' => 'Pending & Upcoming', 'history' => 'Transaction History', 'statement' => 'Month-wise Statement'] as $key => $label)
                <button type="button" wire:click="$set('panel', '{{ $key }}')"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold {{ $panel === $key ? 'bg-white text-orange-600 shadow-sm dark:bg-zinc-800' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="flex items-center gap-2 text-xs text-zinc-500">
            <span>Leave year</span>
            <select wire:model.live="leaveYearId" class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-zinc-900">
                @foreach($leaveYears as $y)
                    <option value="{{ $y->id }}">{{ $y->label }} ({{ $y->starts_on->format('d M Y') }} – {{ $y->ends_on->format('d M Y') }})</option>
                @endforeach
            </select>
        </div>
    </div>

    @if($panel === 'balances')
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
            @forelse($this->cards as $card)
                @php $s = $card['summary']; $t = $card['type']; @endphp
                <div wire:key="card-{{ $t->id }}" class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                    <div class="flex items-start justify-between">
                        <div class="text-xs font-bold uppercase tracking-widest text-zinc-500">{{ $t->name }}</div>
                        @if($s['ledger_status'] === 'needs_hr_review')<flux:badge size="sm" color="amber">Being reviewed by HR</flux:badge>@endif
                    </div>
                    <div class="mt-3 flex items-end gap-6">
                        <div>
                            <div class="text-3xl font-extrabold {{ $s['available_to_request'] < 0 ? 'text-rose-600' : 'text-zinc-900 dark:text-white' }}">{{ $fmt($s['available_to_request']) }}</div>
                            <div class="text-[11px] text-zinc-500">Available to request</div>
                        </div>
                        <div>
                            <div class="text-lg font-bold text-zinc-700 dark:text-zinc-200">{{ $fmt($s['approved_available']) }}</div>
                            <div class="text-[11px] text-zinc-500">Approved balance</div>
                        </div>
                    </div>
                    <dl class="mt-4 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        @foreach([
                            'Base Entitlement' => $s['base'],
                            'Carry Forward' => $s['carry_forward'],
                            'Add-On' => $s['add_on'],
                            'Accrued' => $s['accrued'],
                            'Adjustments' => round($s['adjustment_credit'] - $s['adjustment_debit'], 2),
                            'Opening Balance' => $s['opening'],
                            'Used' => $s['used'],
                            'Pending' => $s['pending'],
                            'Expired' => $s['expired'],
                            'Encashed' => $s['encashed'],
                        ] as $label => $value)
                            @if($value != 0 || in_array($label, ['Base Entitlement', 'Used', 'Pending'], true))
                                <div class="flex justify-between border-b border-dashed border-zinc-100 py-0.5 dark:border-white/5">
                                    <dt class="text-zinc-500">{{ $label }}</dt>
                                    <dd class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $label === 'Adjustments' ? $signed($value) : $fmt($value) }}</dd>
                                </div>
                            @endif
                        @endforeach
                    </dl>
                    <div class="mt-2 text-[11px] text-zinc-400">Leave year {{ $this->year->starts_on->format('d M Y') }} – {{ $this->year->ends_on->format('d M Y') }}</div>
                    <div class="mt-3 flex flex-wrap gap-2">
                        <flux:button size="xs" variant="primary" wire:click="apply({{ $t->id }})">Apply Leave</flux:button>
                        <flux:button size="xs" wire:click="showHistory({{ $t->id }})">View History</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $t->id }})">Statement</flux:button>
                    </div>
                </div>
            @empty
                <div class="col-span-full rounded-2xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500">No leave balances for {{ $this->year->label }} yet.</div>
            @endforelse
        </div>
    @endif

    @if($panel === 'requests')
        @php $g = $this->requestGroups; @endphp
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            @foreach(['Pending' => count($g['pending'] ?? []), 'Needs Information' => count($g['needs_info'] ?? []), 'Approved Upcoming' => count($g['upcoming'] ?? []), 'Rejected' => count($g['rejected'] ?? []), 'Encashment Pending' => $g['encashment_pending'] ?? 0] as $label => $n)
                <div class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-white/10 dark:bg-zinc-900">
                    <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ $label }}</div>
                    <div class="text-xl font-extrabold text-zinc-900 dark:text-white">{{ $n }}</div>
                </div>
            @endforeach
        </div>
        <div class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <table class="min-w-full text-xs">
                <thead class="bg-zinc-50 text-left text-[10px] font-bold uppercase tracking-wider text-zinc-500 dark:bg-white/5">
                    <tr><th class="px-3 py-2">Leave Type</th><th class="px-3 py-2">Dates</th><th class="px-3 py-2 text-right">Days</th><th class="px-3 py-2">Current Stage</th><th class="px-3 py-2">Status</th></tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse(collect($g['pending'] ?? [])->merge($g['needs_info'] ?? [])->merge($g['upcoming'] ?? [])->merge($g['rejected'] ?? []) as $r)
                        <tr wire:key="req-{{ $r->id }}">
                            <td class="px-3 py-2">{{ $r->leaveType?->name }}</td>
                            <td class="px-3 py-2">{{ $r->start_date->format('d M Y') }}@if(! $r->end_date->eq($r->start_date)) – {{ $r->end_date->format('d M Y') }}@endif</td>
                            <td class="px-3 py-2 text-right">{{ $fmt($r->days) }}</td>
                            <td class="px-3 py-2">{{ \App\Livewire\TimeOff\MyLeaveBalances::stage($r) }}</td>
                            <td class="px-3 py-2"><flux:badge size="sm">{{ str_replace('_', ' ', $r->status) }}</flux:badge></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-sm text-zinc-500">Nothing pending or upcoming.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    @if($panel === 'history')
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-3 flex flex-wrap gap-3 text-xs">
                <select wire:model.live="historyTypeId" class="rounded-lg border border-zinc-200 bg-white px-2 py-1 dark:border-white/10 dark:bg-zinc-900">
                    <option value="">All leave types</option>
                    @foreach($this->cards as $c)<option value="{{ $c['type']->id }}">{{ $c['type']->name }}</option>@endforeach
                </select>
                <input type="month" wire:model.live="historyMonth" class="rounded-lg border border-zinc-200 bg-white px-2 py-1 dark:border-white/10 dark:bg-zinc-900">
            </div>
            <ol class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($this->history as $h)
                    <li class="flex items-start justify-between gap-3 py-2 text-sm">
                        <div class="flex gap-3">
                            <span class="w-20 shrink-0 text-xs text-zinc-400">{{ $h['date']->format('d M Y') }}</span>
                            <div>
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $h['label'] }} <span class="font-normal text-zinc-400">· {{ $h['leave_type'] }}</span></div>
                                @if($h['reason'])<div class="text-xs text-zinc-500">Reason: {{ $h['reason'] }}</div>@endif
                                @if($h['expires_on'])<div class="text-[11px] text-amber-600">Expires {{ $h['expires_on']->format('d M Y') }}</div>@endif
                            </div>
                        </div>
                        <div class="text-right font-bold {{ $h['days'] < 0 ? 'text-rose-600' : 'text-emerald-600' }}">{{ $signed($h['days']) }}</div>
                    </li>
                @empty
                    <li class="py-8 text-center text-sm text-zinc-500">No movements yet.</li>
                @endforelse
            </ol>
        </div>
    @endif

    @if($panel === 'statement')
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <select wire:model.live="statementTypeId" class="mb-3 rounded-lg border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-zinc-900">
                @foreach($this->cards as $c)<option value="{{ $c['type']->id }}">{{ $c['type']->name }}</option>@endforeach
            </select>
            @include('livewire.time-off.partials.monthly-statement', ['months' => $this->statement, 'fmt' => $fmt])
        </div>
    @endif
</div>
