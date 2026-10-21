<div class="space-y-4" data-leave-balances-panel="{{ $panel }}">
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
        @if($embedded)
            {{-- Inside My Time Off its tab bar picks the panel; only the year is chosen here. --}}
            <p class="text-sm font-semibold text-zinc-700 dark:text-zinc-200">{{ ['balances' => 'Current balances', 'requests' => 'Pending & upcoming requests', 'history' => 'Balance movements', 'statement' => 'Month-wise statement'][$panel] ?? '' }}</p>
        @else
            <div class="flex max-w-full gap-1 overflow-x-auto rounded-xl bg-zinc-100 p-1 dark:bg-white/5">
                @foreach(['balances' => 'My Balances', 'requests' => 'Pending & Upcoming', 'history' => 'Transaction History', 'statement' => 'Month-wise Statement'] as $key => $label)
                    <button type="button" wire:click="$set('panel', '{{ $key }}')"
                        class="shrink-0 whitespace-nowrap rounded-lg px-3 py-1.5 text-xs font-semibold {{ $panel === $key ? 'bg-white text-orange-600 shadow-sm dark:bg-zinc-800' : 'text-zinc-600 dark:text-zinc-400' }}">{{ $label }}</button>
                @endforeach
            </div>
        @endif
        <div class="flex items-center gap-2 text-xs text-zinc-500">
            <span>Leave year</span>
            <select wire:model.live="leaveYearId" class="rounded-lg border border-zinc-200 bg-white px-2 py-1 text-xs dark:border-white/10 dark:bg-zinc-900">
                @foreach($leaveYears as $y)
                    <option value="{{ $y->id }}">{{ $y->label }} ({{ $y->starts_on->format('d M Y') }} – {{ $y->ends_on->format('d M Y') }})</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- A V2 policy (one with Earned Leave) gets the category view; a legacy
         policy keeps its own cards, exactly as My Time Off's header decides. --}}
    @if($panel === 'balances' && \App\Services\Leave\LeavePolicyEngine::enabled() && ($this->v2Balances === null || $this->v2Balances['earned'] !== null))
        @include('livewire.time-off.partials.v2-balances')
    @elseif($panel === 'balances')
        @php
            $o = $this->overview;
            $c = $o['csl'];
            $co = $o['comp_off'];
            $num = fn ($v) => $fmt($v);
            $negClass = fn ($v) => (float) $v < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-zinc-900 dark:text-white';
            $mdlColor = fn ($status) => match ($status) { 'worked' => 'emerald', 'completed' => 'zinc', default => 'orange' };
        @endphp

        {{-- Conexus policy: 12 CSL + 6 MDL, Comp Off earned. Every figure is the
             calculator's for the selected leave year, never floored. --}}
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

            {{-- CSL --}}
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest text-zinc-500">{{ $c['type']->name ?? 'Casual / Sick Leave' }}</div>
                        <div class="mt-0.5 text-[11px] text-zinc-400">Policy: {{ $num($o['policy']['csl_days']) }} days / year · 1 day per completed month</div>
                    </div>
                    <flux:badge size="sm" color="emerald">No expiry · never lapses</flux:badge>
                </div>

                @if($c && $c['balance'])
                    @php $s = $c['summary']; $addOns = round($s['add_on'] + $s['adjustment_credit'] - $s['adjustment_debit'] + $s['opening'], 2); @endphp
                    <div class="mt-3 flex items-end gap-6">
                        <div>
                            <div class="text-3xl font-extrabold tabular-nums {{ $negClass($s['available_to_request']) }}">{{ $num($s['available_to_request']) }}</div>
                            <div class="text-[11px] text-zinc-500">Available to request</div>
                        </div>
                        <div>
                            <div class="text-lg font-bold tabular-nums {{ (float) $s['approved_available'] < 0 ? 'text-rose-600' : 'text-zinc-700 dark:text-zinc-200' }}">{{ $num($s['approved_available']) }}</div>
                            <div class="text-[11px] text-zinc-500">Approved balance</div>
                        </div>
                    </div>
                    <dl class="mt-4 space-y-1 text-xs">
                        @foreach(array_filter([
                            ['Accrued this year', round($s['base'] + $s['accrued'], 2), true],
                            ['Carry forward', $s['carry_forward'], true],
                            ['Add-ons / adjustments', $addOns, $addOns != 0],
                            ['Used', -$s['used'], true],
                            ['Pending', -$s['pending'], true],
                            ['Encashed', -$s['encashed'], true],
                            ['Expired', -$s['expired'], $s['expired'] != 0],
                        ], fn ($r) => $r[2]) as [$label, $value])
                            <div class="flex justify-between border-b border-dashed border-zinc-100 py-0.5 dark:border-white/5">
                                <dt class="text-zinc-500">{{ $label }}</dt>
                                <dd class="font-semibold tabular-nums text-zinc-800 dark:text-zinc-200">{{ $value > 0 ? '+' : '' }}{{ $num($value) }}</dd>
                            </div>
                        @endforeach
                    </dl>
                    @if($s['ledger_status'] === 'needs_hr_review')
                        <p class="mt-2 text-[11px] text-amber-600">HR is reviewing this balance.</p>
                    @endif
                    <div class="mt-4 flex flex-wrap gap-2">
                        @if($c['requestable'])
                            <flux:button size="xs" variant="primary" wire:click="apply({{ $c['type']->id }})">Apply CSL</flux:button>
                        @endif
                        <flux:button size="xs" wire:click="showHistory({{ $c['type']->id }})">View history</flux:button>
                        <flux:button size="xs" variant="ghost" wire:click="showStatement({{ $c['type']->id }})">Statement</flux:button>
                        @if($c['encashable'] && $s['available_to_request'] > 0)
                            <flux:button size="xs" variant="ghost" icon="banknotes" wire:click="encash({{ $c['type']->id }})">Encash</flux:button>
                        @endif
                    </div>
                @else
                    <p class="mt-4 text-sm text-zinc-500">No Casual / Sick Leave balance for {{ $this->year->label }} yet. 1 day is credited after each completed month; contact HR if this looks wrong.</p>
                @endif
            </div>

            {{-- MDL --}}
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest text-zinc-500">Mandatory December Leave</div>
                        <div class="mt-0.5 text-[11px] text-zinc-400">{{ $o['mdl']['expected'] }} mandatory company shutdown days</div>
                    </div>
                    <flux:badge size="sm">Not a balance</flux:badge>
                </div>
                <p class="mt-3 text-xs text-zinc-500">The company is closed on these dates. They are not taken from your CSL and you don't apply for them.</p>
                <ul class="mt-3 space-y-1.5 text-xs">
                    @forelse($o['mdl']['dates'] as $d)
                        <li class="flex items-center justify-between gap-2">
                            <span class="font-medium text-zinc-700 dark:text-zinc-200">{{ $d['date']->format('D, j M Y') }}</span>
                            <span class="flex items-center gap-1.5">
                                @if($d['comp_off_earned'])<flux:badge size="sm" color="emerald">Comp Off earned</flux:badge>@endif
                                <flux:badge size="sm" :color="$mdlColor($d['status'])">{{ ucfirst($d['status']) }}</flux:badge>
                            </span>
                        </li>
                    @empty
                        <li class="text-zinc-500">HR has not configured the December dates for {{ $this->year->label }} yet.</li>
                    @endforelse
                </ul>
                @if($o['mdl']['configured'] > 0 && $o['mdl']['configured'] !== $o['mdl']['expected'])
                    <p class="mt-2 text-[11px] text-amber-600">{{ $o['mdl']['configured'] }} of {{ $o['mdl']['expected'] }} dates are configured.</p>
                @endif
                <p class="mt-3 text-[11px] text-zinc-400">Working an MDL day earns one Comp Off credit.</p>
            </div>

            {{-- Comp Off --}}
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <div class="text-xs font-bold uppercase tracking-widest text-zinc-500">Comp Off</div>
                        <div class="mt-0.5 text-[11px] text-zinc-400">Earned by working an MDL day or applicable public holiday</div>
                    </div>
                    <flux:badge size="sm" color="emerald">No expiry</flux:badge>
                </div>
                @php $cs = $co['summary'] ?? null; @endphp
                <div class="mt-3">
                    <div class="text-3xl font-extrabold tabular-nums {{ $negClass($cs['approved_available'] ?? 0) }}">{{ $num($cs['approved_available'] ?? 0) }}</div>
                    <div class="text-[11px] text-zinc-500">Available</div>
                </div>
                <dl class="mt-4 space-y-1 text-xs">
                    @foreach([['Credits earned', $co['earned'] ?? 0], ['Used', -($cs['used'] ?? 0)], ['Pending approval', -($cs['pending'] ?? 0)]] as [$label, $value])
                        <div class="flex justify-between border-b border-dashed border-zinc-100 py-0.5 dark:border-white/5">
                            <dt class="text-zinc-500">{{ $label }}</dt>
                            <dd class="font-semibold tabular-nums text-zinc-800 dark:text-zinc-200">{{ $value > 0 ? '+' : '' }}{{ $num($value) }}</dd>
                        </div>
                    @endforeach
                </dl>
                <div class="mt-4 flex flex-wrap gap-2">
                    @if($co && $co['requestable'] && ($cs['available_to_request'] ?? 0) > 0)
                        <flux:button size="xs" variant="primary" wire:click="apply({{ $co['type']->id }})">Apply Comp Off</flux:button>
                    @endif
                    @if($co)
                        <flux:button size="xs" wire:click="showHistory({{ $co['type']->id }})">View history</flux:button>
                    @endif
                </div>
            </div>
        </div>

        @if($o['others']->isNotEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="text-xs font-bold uppercase tracking-widest text-zinc-500">Other leave</div>
                <p class="mt-0.5 text-[11px] text-zinc-400">Special or statutory leave. Not part of your Available Leave.</p>
                <div class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach($o['others'] as $other)
                        <div wire:key="other-{{ $other['type']->id }}" class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs dark:bg-white/5">
                            <span class="text-zinc-600 dark:text-zinc-300">{{ $other['type']->name }}</span>
                            <span class="font-semibold tabular-nums {{ $negClass($other['summary']['approved_available']) }}">{{ $num($other['summary']['approved_available']) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endif

    @if($panel === 'requests')
        @php
            $g = $this->requestGroups;
            $chip = fn (string $status) => match ($status) {
                'approved' => ['Approved', 'emerald'],
                'rejected' => ['Rejected', 'rose'],
                'cancelled' => ['Cancelled', 'zinc'],
                'more_info_requested' => ['Needs info', 'blue'],
                default => ['Pending', 'amber'],
            };
            $rows = collect($g['pending'] ?? [])->merge($g['needs_info'] ?? [])->merge($g['upcoming'] ?? [])->merge($g['rejected'] ?? []);
            $dates = fn ($r) => $r->start_date->format('d M Y').(! $r->end_date->eq($r->start_date) ? ' – '.$r->end_date->format('d M Y') : '');
            $category = fn ($r) => $r->isCombined() ? 'Combined Leave' : ($r->main_category ? \App\Services\Leave\LeaveCategory::label($r->main_category) : ($r->leaveType?->name ?? 'Leave'));
            $reasonOf = fn ($r) => ($r->isCombined() || $r->main_category) ? $r->leaveType?->name : null;
            $approver = fn ($r) => $r->hrReviewer?->name ?? $r->reviewer?->name;
            $cancellable = fn ($r) => in_array($r->status, ['pending', 'pending_hr', 'approved'], true);
            // View: open the full application row in Leave History (table or phone card).
            $viewDetails = fn ($r) => "\$dispatch('leave-view', { view: 'history', sub: 'requests' }); setTimeout(() => ['request-{$r->id}', 'request-card-{$r->id}'].map(i => document.getElementById(i)).find(e => e && e.offsetParent)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 250)";
        @endphp
        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            @foreach(['Pending' => count($g['pending'] ?? []), 'Needs Information' => count($g['needs_info'] ?? []), 'Approved Upcoming' => count($g['upcoming'] ?? []), 'Rejected' => count($g['rejected'] ?? []), 'Encashment Pending' => $g['encashment_pending'] ?? 0] as $label => $n)
                <div class="ui-surface p-3">
                    <div class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ $label }}</div>
                    <div class="text-xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ $n }}</div>
                </div>
            @endforeach
        </div>

        @if($rows->isEmpty())
            <div class="ui-surface p-8 text-center text-sm text-zinc-500 dark:text-zinc-400">Nothing pending or upcoming.</div>
        @else
            {{-- Desktop / tablet table --}}
            <div class="ui-surface hidden overflow-x-auto sm:block" data-requests-table>
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                        <tr><th class="px-4 py-2.5">Dates</th><th class="px-4 py-2.5">Leave</th><th class="px-4 py-2.5">Reason</th><th class="px-4 py-2.5 text-right">Days</th><th class="px-4 py-2.5">Status</th><th class="px-4 py-2.5">Current Stage</th><th class="px-4 py-2.5">Approver</th><th class="px-4 py-2.5 text-right">Actions</th></tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($rows as $r)
                            @php [$chipLabel, $chipColor] = $chip($r->status); @endphp
                            <tr wire:key="req-{{ $r->id }}">
                                <td class="whitespace-nowrap px-4 py-2.5 font-medium text-zinc-900 dark:text-white">{{ $dates($r) }}</td>
                                <td class="px-4 py-2.5 text-zinc-700 dark:text-zinc-200">{{ $category($r) }}</td>
                                <td class="px-4 py-2.5 text-zinc-500 dark:text-zinc-400">{{ $reasonOf($r) ?? '—' }}</td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ $fmt($r->days) }}</td>
                                <td class="px-4 py-2.5"><x-pulse.badge :color="$chipColor">{{ $chipLabel }}</x-pulse.badge></td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-300">{{ \App\Livewire\TimeOff\MyLeaveBalances::stage($r) }}</td>
                                <td class="px-4 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $approver($r) ?? '—' }}</td>
                                <td class="whitespace-nowrap px-4 py-2.5 text-right">
                                    @if($embedded)
                                        <flux:button size="xs" variant="ghost" x-on:click="{{ $viewDetails($r) }}">View</flux:button>
                                    @endif
                                    @if($embedded && $cancellable($r))
                                        <flux:button size="xs" variant="ghost" wire:click="$parent.cancelRequest({{ $r->id }})" wire:confirm="Cancel this leave request?">Cancel</flux:button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Phones: one card per request --}}
            <ul class="space-y-2 sm:hidden" data-requests-cards>
                @foreach($rows as $r)
                    @php [$chipLabel, $chipColor] = $chip($r->status); @endphp
                    <li wire:key="req-card-{{ $r->id }}" class="ui-surface p-4">
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <p class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $dates($r) }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $category($r) }}@if($reasonOf($r)) · {{ $reasonOf($r) }}@endif · {{ $fmt($r->days) }} {{ \Illuminate\Support\Str::plural('day', (float) $r->days) }}</p>
                            </div>
                            <x-pulse.badge :color="$chipColor">{{ $chipLabel }}</x-pulse.badge>
                        </div>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ \App\Livewire\TimeOff\MyLeaveBalances::stage($r) }}@if($approver($r)) · {{ $approver($r) }}@endif</p>
                        @if($embedded)
                            <div class="mt-2 flex gap-2">
                                <flux:button size="xs" variant="ghost" x-on:click="{{ $viewDetails($r) }}">View</flux:button>
                                @if($cancellable($r))
                                    <flux:button size="xs" variant="ghost" wire:click="$parent.cancelRequest({{ $r->id }})" wire:confirm="Cancel this leave request?">Cancel</flux:button>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
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
