{{-- A full page: <flux:main> puts it beside the sidebar, like every other attendance page. --}}
<flux:main class="min-h-screen bg-[#FFF8F3] p-4 md:p-6">
<div class="mx-auto max-w-[1400px] space-y-4" data-missed-punch-allowance>
    @php
        $statusChip = [
            'ok' => ['Within allowance', 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-500/10 dark:text-emerald-300 dark:ring-emerald-500/30'],
            'warning' => ['Warning', 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-500/10 dark:text-amber-300 dark:ring-amber-500/30'],
            'exceeded' => ['Over allowance', 'bg-red-50 text-red-700 ring-red-200 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/30'],
        ];
        $regTone = ['pending' => 'text-amber-600', 'approved' => 'text-emerald-600', 'rejected' => 'text-red-600'];
    @endphp

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold text-zinc-900 dark:text-white">Missed Punches</h1>
            <p class="mt-0.5 text-sm text-zinc-500 dark:text-slate-400">
                {{ $allowance }} missed punches allowed per calendar month — every day counts, weekends too — starting again on the 1st.
                @if($warningAt > 0) Early warning at {{ $warningAt }}. @endif
                One more than {{ $allowance }} flags the employee for your review.
                <a href="{{ route('attendance.settings') }}" wire:navigate class="font-semibold text-orange-600 hover:underline">Change in Attendance Settings</a>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <button type="button" wire:click="previousMonth" class="inline-flex size-9 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" aria-label="Previous month"><flux:icon.chevron-left class="size-4" /></button>
            <div class="min-w-[9rem] rounded-xl border border-zinc-200 bg-white px-3 py-1.5 text-center text-sm font-semibold text-zinc-800 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" data-missed-month-label>
                {{ $monthLabel }}
            </div>
            <button type="button" wire:click="nextMonth" @disabled($isThisMonth) class="inline-flex size-9 items-center justify-center rounded-xl border border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 disabled:opacity-40 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300" aria-label="Next month"><flux:icon.chevron-right class="size-4" /></button>
            @unless($isThisMonth)
                <button type="button" wire:click="thisMonth" class="rounded-xl px-3 py-2 text-xs font-semibold text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-500/10">This month</button>
            @endunless
        </div>
    </div>

    @if($startsOn)
        <div class="flex items-start gap-3 rounded-2xl border border-sky-200 bg-sky-50 px-4 py-3 text-sm text-sky-900 dark:border-sky-500/30 dark:bg-sky-500/10 dark:text-sky-100" data-missed-punch-not-started>
            <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-sky-600 dark:text-sky-300" />
            <div>
                <span class="font-semibold">Missed-punch tracking starts on {{ \Carbon\CarbonImmutable::parse($startsOn)->userDate() }}.</span>
                Days before it are not counted, alerted, flagged or scored per punch.
                <a href="{{ route('attendance.settings') }}" wire:navigate class="font-semibold text-sky-700 underline-offset-2 hover:underline dark:text-sky-300">Change the start date</a>
            </div>
        </div>
    @endif

    {{-- KPIs --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            ['Employees with missed punches', $kpis['missed'], 'text-zinc-900 dark:text-white'],
            ['At the early warning', $kpis['warning'], 'text-amber-600'],
            ['Over the allowance', $kpis['exceeded'], 'text-red-600'],
            ['Flags to review', $kpis['open'], 'text-red-600'],
        ] as [$label, $value, $tone])
            <div class="ui-surface p-4">
                <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
                <div class="mt-1 text-2xl font-black tabular-nums {{ $tone }}">{{ $value }}</div>
            </div>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="ui-surface flex flex-wrap items-end gap-3 p-4">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search name or employee ID" class="max-w-xs" />
        <flux:select wire:model.live="departmentId" class="max-w-xs">
            <flux:select.option value="">All departments</flux:select.option>
            @foreach($departments as $department)
                <flux:select.option value="{{ $department->id }}">{{ $department->name }}</flux:select.option>
            @endforeach
        </flux:select>
        <flux:select wire:model.live="status" class="max-w-xs">
            <flux:select.option value="all">Everyone</flux:select.option>
            <flux:select.option value="missed">With missed punches</flux:select.option>
            <flux:select.option value="warning">At the warning or over</flux:select.option>
            <flux:select.option value="exceeded">Over the allowance</flux:select.option>
            <flux:select.option value="open">Flags to review</flux:select.option>
        </flux:select>
    </div>

    {{-- Employee-wise this month --}}
    <div class="ui-surface overflow-x-auto">
        <table class="w-full min-w-[860px] text-sm" data-missed-table>
            <thead>
                <tr class="border-b border-zinc-100 bg-zinc-50/70 text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:border-white/[0.06] dark:bg-white/[0.02] dark:text-slate-400">
                    <th class="px-4 py-2.5">Employee</th>
                    <th class="px-3 py-2.5">Department</th>
                    <th class="px-3 py-2.5">Missed this month</th>
                    <th class="px-3 py-2.5">Status</th>
                    <th class="px-3 py-2.5">Regularisation requests</th>
                    <th class="px-3 py-2.5">Flag</th>
                    <th class="px-4 py-2.5 text-right">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.05]">
                @forelse($rows as $row)
                    @php
                        $employee = $row['employee'];
                        [$chipLabel, $chipTone] = $statusChip[$row['status']];
                        $pct = min(100, (int) round($row['count'] / max(1, $allowance) * 100));
                    @endphp
                    <tr wire:key="missed-row-{{ $employee->id }}" @class(['text-zinc-700 dark:text-slate-300', 'bg-red-50/50 dark:bg-red-500/[0.05]' => $row['status'] === 'exceeded']) data-missed-employee="{{ $employee->id }}">
                        <td class="px-4 py-3">
                            <div class="font-semibold text-zinc-900 dark:text-white">{{ $employee->user->name }}</div>
                            <div class="text-xs text-zinc-400">{{ $employee->employee_id }}</div>
                        </td>
                        <td class="px-3 py-3">{{ $employee->department?->name ?? '—' }}</td>
                        <td class="px-3 py-3">
                            <div class="flex items-center gap-2">
                                <span class="font-bold tabular-nums" data-missed-count>{{ $row['count'] }} / {{ $allowance }}</span>
                                <div class="h-1.5 w-24 overflow-hidden rounded-full bg-zinc-100 dark:bg-white/10">
                                    <div @class(['h-full rounded-full', 'bg-emerald-500' => $row['status'] === 'ok', 'bg-amber-500' => $row['status'] === 'warning', 'bg-red-500' => $row['status'] === 'exceeded']) style="width: {{ $pct }}%"></div>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 py-3"><span class="inline-flex rounded-full px-2.5 py-0.5 text-[11px] font-semibold ring-1 ring-inset {{ $chipTone }}">{{ $chipLabel }}</span></td>
                        <td class="px-3 py-3 text-xs">
                            @if($row['requests']->isEmpty())
                                <span class="text-zinc-400">None</span>
                            @else
                                @foreach($row['requests']->countBy('status') as $state => $n)
                                    <span class="mr-2 font-semibold {{ $regTone[$state] ?? 'text-zinc-500' }}">{{ $n }} {{ $state }}</span>
                                @endforeach
                            @endif
                        </td>
                        <td class="px-3 py-3 text-xs">
                            @if($row['flag'])
                                @if($row['flag']->isReviewed())
                                    <span class="font-semibold text-emerald-600">Reviewed</span>
                                    <div class="text-zinc-400">{{ $row['flag']->reviewer?->name }}, {{ $row['flag']->reviewed_at->toImmutable()->userDate() }}</div>
                                @else
                                    <span class="font-semibold text-red-600" data-flag-open>Flagged — to review</span>
                                    <div class="text-zinc-400">{{ $row['flag']->flagged_at->toImmutable()->userDate() }}</div>
                                @endif
                            @else
                                <span class="text-zinc-300 dark:text-slate-600">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex justify-end gap-2">
                                @if($row['count'] > 0 || $row['requests']->isNotEmpty())
                                    <button type="button" wire:click="toggle({{ $employee->id }})" class="rounded-lg px-2 py-1 text-xs font-semibold text-orange-600 hover:bg-orange-50 dark:hover:bg-orange-500/10">{{ $expanded === $employee->id ? 'Hide' : 'Details' }}</button>
                                @endif
                                @if($row['flag'] && ! $row['flag']->isReviewed())
                                    <button type="button" wire:click="openReview({{ $row['flag']->id }})" class="rounded-lg bg-orange-500 px-2.5 py-1 text-xs font-semibold text-white hover:bg-orange-600" data-review-flag="{{ $row['flag']->id }}">Review</button>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @if($expanded === $employee->id)
                        <tr wire:key="missed-detail-{{ $employee->id }}" class="bg-zinc-50/60 dark:bg-white/[0.02]">
                            <td colspan="7" class="px-4 py-3">
                                <div class="grid gap-4 md:grid-cols-2">
                                    <div>
                                        <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Missed punches</div>
                                        <ul class="space-y-1 text-xs" data-missed-detail>
                                            @forelse($row['missed'] as $missed)
                                                <li><span class="font-semibold">{{ \Carbon\CarbonImmutable::parse($missed['date'])->userDate() }}</span> — {{ $missed['label'] }}@if($missed['resolved']) <span class="text-violet-600">(regularised)</span>@endif</li>
                                            @empty
                                                <li class="text-zinc-400">None this month.</li>
                                            @endforelse
                                        </ul>
                                    </div>
                                    <div>
                                        <div class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-400">Regularisation requests</div>
                                        <ul class="space-y-1 text-xs">
                                            @forelse($row['requests'] as $request)
                                                <li>
                                                    <span class="font-semibold">{{ \Carbon\CarbonImmutable::parse($request->work_date)->userDate() }}</span> —
                                                    <span class="font-semibold {{ $regTone[$request->status] ?? '' }}">{{ ucfirst($request->status) }}</span>
                                                    @if($request->reviewer) by {{ $request->reviewer->name }} @endif
                                                    @if($request->reason) · {{ \Illuminate\Support\Str::limit($request->reason, 80) }} @endif
                                                </li>
                                            @empty
                                                <li class="text-zinc-400">No requests this month.</li>
                                            @endforelse
                                        </ul>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr><td colspan="7" class="px-4 py-10 text-center text-sm text-zinc-400">No employees match.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Flag history --}}
    <div class="ui-surface overflow-x-auto">
        <div class="border-b border-zinc-100 px-4 py-3 dark:border-white/[0.06]">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white">Flag history</h2>
            <p class="text-xs text-zinc-500 dark:text-slate-400">Every month an employee went over the allowance, and HR's review. The latest 50.</p>
        </div>
        <table class="w-full min-w-[760px] text-sm" data-flag-history>
            <thead>
                <tr class="text-left text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-slate-400">
                    <th class="px-4 py-2">Period</th><th class="px-3 py-2">Employee</th><th class="px-3 py-2">Department</th>
                    <th class="px-3 py-2">Missed / allowed</th><th class="px-3 py-2">Flagged</th><th class="px-3 py-2">Review</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/[0.05]">
                @forelse($history as $flag)
                    <tr wire:key="flag-{{ $flag->id }}" class="text-zinc-700 dark:text-slate-300">
                        <td class="px-4 py-2.5">{{ $flag->periodLabel() }}</td>
                        <td class="px-3 py-2.5 font-semibold">{{ $flag->employee?->user?->name ?? '—' }}</td>
                        <td class="px-3 py-2.5">{{ $flag->employee?->department?->name ?? '—' }}</td>
                        <td class="px-3 py-2.5 tabular-nums">{{ $flag->missed_count }} / {{ $flag->allowance }}</td>
                        <td class="px-3 py-2.5">{{ $flag->flagged_at->toImmutable()->userDate() }}</td>
                        <td class="px-3 py-2.5 text-xs">
                            @if($flag->isReviewed())
                                <span class="font-semibold text-emerald-600">{{ $flag->reviewer?->name ?? 'HR' }}</span>, {{ $flag->reviewed_at->toImmutable()->userDate() }}
                                <div class="text-zinc-500">{{ $flag->review_note }}</div>
                            @elseif(Auth::user()->coversEmployee($flag->employee))
                                <button type="button" wire:click="openReview({{ $flag->id }})" class="font-semibold text-orange-600 hover:underline">Review</button>
                            @else
                                <span class="text-red-600">Not reviewed</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-8 text-center text-sm text-zinc-400">No one has gone over the allowance yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Review --}}
    <flux:modal name="missed-punch-review" class="md:w-[28rem]">
        <form wire:submit="saveReview" class="space-y-4">
            <div>
                <flux:heading size="lg">Review missed punches</flux:heading>
                @if($reviewing)
                    <flux:text class="mt-1">{{ $reviewing->employee?->user?->name }} — {{ $reviewing->missed_count }} missed punches ({{ $reviewing->periodLabel() }}), over the {{ $reviewing->allowance }} allowed.</flux:text>
                @endif
            </div>
            <flux:textarea wire:model="reviewNote" label="What was done" placeholder="e.g. Spoke to the employee; device issue on Tuesday; reminded to punch Face IN after lunch." rows="4" />
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Mark reviewed</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
</flux:main>
