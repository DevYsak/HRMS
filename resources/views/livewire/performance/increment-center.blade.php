<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Increment Center</h1>
            <p class="pulse-page-subtitle">Performance-linked increments — annual scores, department calibration, band matrix &amp; budget</p>
        </div>
        <div class="flex items-center gap-2">
            @if($cycles->count() > 1)
                <x-clean-select model="cycleId" :live="true" :options="$cycles->map(fn ($c) => ['value' => $c->id, 'label' => 'FY '.$c->financial_year])->all()" />
            @endif
            <flux:button wire:click="newCycleForm" variant="primary" icon="plus">Open cycle</flux:button>
        </div>
    </div>

    @if(! $cycle)
        <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 py-16 text-center">
            <flux:icon.banknotes class="mx-auto mb-2 size-8 text-zinc-300" />
            <p class="text-sm text-zinc-400">No increment cycle yet. Open the FY cycle to compute annual scores and draft proposals.</p>
        </div>
    @else
        {{-- Cycle header: status, actions, budget bar --}}
        <div class="pulse-card space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <span class="text-lg font-black text-zinc-900 dark:text-white">FY {{ $cycle->financial_year }}</span>
                    <span class="rounded-full bg-zinc-100 dark:bg-zinc-800 px-2.5 py-0.5 text-[10px] font-bold uppercase tracking-widest text-zinc-500">{{ $cycle->statusLabel() }}</span>
                    <span class="text-xs text-zinc-400">Effective {{ $cycle->effective_date->format('d M Y') }} · Budget {{ rtrim(rtrim(number_format($cycle->budget_percent, 2), '0'), '.') }}% of payroll</span>
                </div>
                <div class="flex items-center gap-2">
                    @if(in_array($cycle->status, ['draft', 'calibration']))
                        <flux:button wire:click="generate" size="sm" icon="calculator" wire:confirm="Recompute annual scores and (re)generate draft proposals for all active employees?">{{ $cycle->status === 'draft' ? 'Generate proposals' : 'Regenerate' }}</flux:button>
                    @endif
                    @if($cycle->status === 'calibration')
                        <flux:button wire:click="submitCycle" size="sm" variant="primary" icon="paper-airplane">Submit for approval</flux:button>
                    @endif
                    @if($cycle->status === 'proposed' && $canApprove)
                        <flux:button wire:click="approveCycle" size="sm" variant="primary" icon="check-badge">Approve cycle</flux:button>
                    @endif
                    @if($cycle->status === 'approved' && $canApprove)
                        <flux:button wire:click="applyCycle" size="sm" variant="primary" icon="rocket-launch" wire:confirm="Apply all approved increments? This writes new salary rows and emails increment letters.">Apply increments</flux:button>
                    @endif
                </div>
            </div>

            {{-- Budget consumption --}}
            @php
                $pct = $budget > 0 ? min(100, round($committed / $budget * 100)) : 0;
                $over = $committed > $budget;
            @endphp
            <div>
                <div class="mb-1 flex items-center justify-between text-xs">
                    <span class="font-semibold {{ $over ? 'text-rose-600' : 'text-zinc-500' }}">Committed ₹{{ number_format($committed) }} / pool ₹{{ number_format($budget) }} (annual)</span>
                    <span class="font-bold {{ $over ? 'text-rose-600' : 'text-emerald-600' }}">{{ $budget > 0 ? round($committed / max($budget, 1) * 100) : 0 }}%</span>
                </div>
                <div class="h-2.5 w-full overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                    <div class="h-full rounded-full {{ $over ? 'bg-rose-500' : 'bg-emerald-500' }} transition-all" style="width: {{ $pct }}%"></div>
                </div>
                @if($over)
                    <p class="mt-1 text-xs font-semibold text-rose-600">Over budget — approval is blocked until proposals are reduced or the budget is raised.</p>
                @endif
            </div>

            {{-- Band distribution + matrix --}}
            <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                <div class="flex items-end gap-3">
                    @foreach(['A', 'B', 'C', 'D', 'E'] as $band)
                        @php $count = $bandCounts[$band] ?? 0; $max = max(1, $bandCounts->max() ?? 1); @endphp
                        <div class="flex flex-1 flex-col items-center gap-1">
                            <div class="w-full rounded-t-lg {{ ['A' => 'bg-emerald-400', 'B' => 'bg-teal-400', 'C' => 'bg-sky-400', 'D' => 'bg-amber-400', 'E' => 'bg-rose-400'][$band] }}" style="height: {{ 8 + ($count / $max) * 56 }}px"></div>
                            <span class="text-[10px] font-bold text-zinc-500">{{ $band }} · {{ $count }}</span>
                        </div>
                    @endforeach
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead><tr class="text-left uppercase tracking-widest text-zinc-400"><th class="py-1 pr-3">Band</th><th class="py-1 pr-3">Range</th><th class="py-1">Default</th></tr></thead>
                        <tbody>
                            @foreach($cycle->matrix->sortBy('band') as $row)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                    <td class="py-1 pr-3 font-bold">{{ $row->band }} — {{ \App\Models\IncrementProposal::BAND_LABELS[$row->band] ?? '' }}</td>
                                    <td class="py-1 pr-3">{{ rtrim(rtrim(number_format($row->min_percent, 1), '0'), '.') }}–{{ rtrim(rtrim(number_format($row->max_percent, 1), '0'), '.') }}%</td>
                                    <td class="py-1">{{ rtrim(rtrim(number_format($row->default_percent, 1), '0'), '.') }}%{{ $row->band === 'E' ? ' + PIP flag' : '' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        {{-- Proposals by department (calibration screen) --}}
        @forelse($byDepartment as $deptName => $proposals)
            <div class="pulse-card">
                <div class="mb-3 flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-400">
                    <flux:icon.building-office-2 class="size-3.5" /> {{ $deptName }}
                    <span class="font-normal normal-case tracking-normal">— {{ $proposals->count() }} {{ \Illuminate\Support\Str::plural('employee', $proposals->count()) }}{{ $proposals->count() < \App\Services\Increments\CalibrationService::SMALL_DEPT_THRESHOLD ? ' (small dept: raw-score bands, no z)' : ' (z-score calibrated)' }}</span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-[10px] uppercase tracking-widest text-zinc-400">
                                <th class="py-2 pr-3">Employee</th>
                                <th class="py-2 pr-3">Annual score</th>
                                <th class="py-2 pr-3">z</th>
                                <th class="py-2 pr-3">Band</th>
                                <th class="py-2 pr-3">Gross ₹/mo</th>
                                <th class="py-2 pr-3">Increment %</th>
                                <th class="py-2 pr-3">New gross</th>
                                <th class="py-2"></th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($proposals as $p)
                                <tr class="border-t border-zinc-100 dark:border-zinc-800 {{ $p->band === 'E' ? 'bg-rose-50/50 dark:bg-rose-500/5' : '' }}">
                                    <td class="py-2 pr-3">
                                        <div class="font-semibold text-zinc-800 dark:text-zinc-100">{{ $p->employee?->user?->name ?? '—' }}</div>
                                        <div class="text-[11px] text-zinc-400">{{ $p->employee?->jobTitle?->name ?? '' }}</div>
                                    </td>
                                    <td class="py-2 pr-3 tabular-nums">
                                        @if($p->annual_raw_score !== null)
                                            {{ number_format($p->annual_raw_score, 1) }} <span class="text-[10px] text-zinc-400">({{ $p->quarters_counted }}q)</span>
                                        @else
                                            <span class="rounded bg-amber-50 dark:bg-amber-500/10 px-1.5 py-0.5 text-[10px] font-bold uppercase text-amber-600">Insufficient data</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 tabular-nums text-zinc-500">{{ $p->calibrated_z !== null ? number_format($p->calibrated_z, 2) : '—' }}</td>
                                    <td class="py-2 pr-3">
                                        @if($p->band)
                                            <span class="rounded-full px-2 py-0.5 text-[10px] font-black {{ ['A' => 'bg-emerald-100 text-emerald-700', 'B' => 'bg-teal-100 text-teal-700', 'C' => 'bg-sky-100 text-sky-700', 'D' => 'bg-amber-100 text-amber-700', 'E' => 'bg-rose-100 text-rose-700'][$p->band] }}">{{ $p->band }}</span>
                                            @if($p->band_overridden)<flux:icon.pencil class="ml-1 inline size-3 text-zinc-400" title="Overridden: {{ $p->override_reason }}" />@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 tabular-nums">{{ number_format($p->current_gross) }}</td>
                                    <td class="py-2 pr-3">
                                        @if($cycle->isEditable() && $p->band)
                                            <input type="number" step="0.5" wire:model="edits.{{ $p->id }}.percent" placeholder="{{ $p->proposed_percent }}"
                                                class="w-20 rounded-lg border-zinc-200 dark:border-zinc-700 dark:bg-zinc-800 px-2 py-1 text-sm tabular-nums" />
                                        @else
                                            <span class="tabular-nums font-semibold">{{ rtrim(rtrim(number_format($p->proposed_percent, 2), '0'), '.') }}%</span>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-3 tabular-nums font-semibold text-zinc-700 dark:text-zinc-200">{{ number_format($p->new_gross) }}</td>
                                    <td class="py-2 text-right whitespace-nowrap">
                                        @if($cycle->isEditable())
                                            @if($p->band)
                                                <flux:button wire:click="saveProposal({{ $p->id }})" size="xs" variant="ghost" icon="check">Save</flux:button>
                                            @endif
                                            <flux:button wire:click="openOverride({{ $p->id }})" size="xs" variant="ghost" icon="adjustments-horizontal">Band</flux:button>
                                        @elseif($p->letter_path)
                                            <span class="text-[10px] font-bold uppercase text-emerald-600">Letter issued</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 py-12 text-center text-sm text-zinc-400">
                No proposals yet — click "Generate proposals" to run the calibration.
            </div>
        @endforelse
    @endif

    {{-- Open-cycle modal --}}
    <flux:modal wire:model.self="showCycleForm" class="w-full max-w-md">
        <form wire:submit="openCycle" class="space-y-5">
            <div>
                <flux:heading size="lg">Open increment cycle</flux:heading>
                <flux:subheading>Aligned to the Conexus year — increments take effect in July.</flux:subheading>
            </div>
            <flux:input wire:model="financialYear" label="Financial year" placeholder="2026-27" required />
            <flux:input wire:model="effectiveDate" type="date" label="Effective date" required />
            <flux:input wire:model="budgetPercent" type="number" step="0.5" min="0.5" max="100" label="Budget (% of annual payroll)" required />
            <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                <flux:button type="button" wire:click="$set('showCycleForm', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check">Open cycle</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Band override modal --}}
    <flux:modal wire:model.self="overrideProposalId" class="w-full max-w-md">
        <form wire:submit="applyOverride" class="space-y-5">
            <div>
                <flux:heading size="lg">Override calibration band</flux:heading>
                <flux:subheading>Overrides reset the % to the new band's default and are written to the audit log with your reason.</flux:subheading>
            </div>
            <x-clean-select model="overrideBand" label="New band" :live="false"
                :options="collect(\App\Models\IncrementProposal::BAND_LABELS)->map(fn ($label, $band) => ['value' => $band, 'label' => $band.' — '.$label])->values()->all()" />
            <flux:textarea wire:model="overrideReason" label="Reason (required, logged)" rows="3" placeholder="Why does this employee's band differ from the calibrated result?" />
            @error('overrideReason')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror
            <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                <flux:button type="button" wire:click="$set('overrideProposalId', null)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check">Apply override</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
