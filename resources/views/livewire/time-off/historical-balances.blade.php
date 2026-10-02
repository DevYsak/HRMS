<flux:main class="space-y-6 p-4 md:p-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Historical Leave Balances</flux:heading>
            <flux:subheading>Migrate closed leave years from HR's own records.</flux:subheading>
        </div>
        <flux:button wire:click="downloadTemplate" icon="arrow-down-tray" variant="ghost" size="sm">
            Download template
        </flux:button>
    </div>

    {{-- What the import will and will not do --}}
    <div class="rounded-2xl border border-amber-200 bg-amber-50/60 p-5 dark:border-amber-500/20 dark:bg-amber-900/10">
        <div class="flex items-start gap-3">
            <flux:icon.information-circle class="mt-0.5 size-5 shrink-0 text-amber-500" />
            <div class="text-sm text-amber-900 dark:text-amber-200">
                <p class="font-bold">A missing figure stays missing.</p>
                <p class="mt-1 text-xs leading-relaxed">
                    Leave <b>used</b> or <b>encashed</b> blank, or write <b>Not Available</b>, where HR does not hold
                    the figure. It is recorded as unknown, never as zero — a zero would say the employee took no leave
                    that year, and the system would then derive a carry-forward amount from it. Rows without a usage
                    figure are marked <b>Awaiting HR Decision</b>: their carry forward is decided by hand on the
                    Carry Forward screen. Existing balances are never overwritten.
                </p>
            </div>
        </div>
    </div>

    {{-- Upload --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="flex flex-wrap items-end gap-3">
            <div class="min-w-64 flex-1">
                <flux:input type="file" wire:model="file" label="Spreadsheet"
                    description="employee_code, leave_year, leave_type, closing_balance, used, encashed, remarks" />
                <flux:error name="file" />
            </div>
            <flux:button wire:click="analyze" variant="primary" icon="magnifying-glass"
                wire:loading.attr="disabled" wire:target="analyze,file">
                <span wire:loading.remove wire:target="analyze">Analyse</span>
                <span wire:loading wire:target="analyze">Reading…</span>
            </flux:button>
        </div>
    </div>

    {{-- Result of the last import --}}
    @if($lastResult)
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50/60 p-4 text-sm dark:border-emerald-500/20 dark:bg-emerald-900/10">
            <b>{{ $lastResult['imported'] }}</b> balance(s) imported,
            <b>{{ $lastResult['skipped'] }}</b> skipped.
            @if($lastResult['awaiting_decision'] > 0)
                <b>{{ $lastResult['awaiting_decision'] }}</b> have no usage figure and await an HR carry-forward decision.
            @endif
        </div>
    @endif

    {{-- Preview --}}
    @if($showPreview)
        @php
            $labels = [
                'known' => ['Known / Calculable', 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400'],
                'awaiting_hr_decision' => ['Awaiting HR Decision', 'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400'],
                'duplicate' => ['Duplicate', 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'],
                'conflict' => ['Conflict', 'bg-orange-100 text-orange-700 dark:bg-orange-900/30 dark:text-orange-400'],
                'invalid' => ['Invalid', 'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400'],
            ];
        @endphp

        <div class="flex flex-wrap items-center gap-2">
            @foreach($labels as $key => [$label, $classes])
                <button type="button" wire:click="$set('statusFilter', '{{ $statusFilter === $key ? '' : $key }}')"
                    @class(['rounded-full px-3 py-1 text-xs font-bold transition', $classes, 'ring-2 ring-offset-1 ring-zinc-400' => $statusFilter === $key])>
                    {{ $label }}: {{ $summary[$key] ?? 0 }}
                </button>
            @endforeach
        </div>

        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                        <th class="px-5 py-2 text-left">Employee</th>
                        <th class="px-3 py-2 text-left">Leave Year</th>
                        <th class="px-3 py-2 text-left">Leave Type</th>
                        <th class="px-3 py-2 text-right">Closing Balance</th>
                        <th class="px-3 py-2 text-right">Used</th>
                        <th class="px-3 py-2 text-right">Encashed</th>
                        <th class="px-5 py-2 text-left">Carry Forward Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @forelse($rows as $row)
                        @php([$label, $classes] = $labels[$row['status']] ?? ['Unknown', ''])
                        <tr class="align-top">
                            <td class="px-5 py-2.5">
                                <div class="font-semibold text-zinc-900 dark:text-white">{{ $row['data']['employee_name'] }}</div>
                                <div class="text-[11px] text-zinc-400">{{ $row['data']['employee_code'] }} · line {{ $row['line'] }}</div>
                            </td>
                            <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $row['data']['leave_year_label'] }}</td>
                            <td class="px-3 py-2.5 text-zinc-600 dark:text-zinc-300">{{ $row['data']['leave_type_code'] }}</td>
                            <td class="px-3 py-2.5 text-right font-semibold text-zinc-900 dark:text-white">
                                {{ $row['data']['closing_balance'] ?? '—' }}
                            </td>
                            <td @class([
                                'px-3 py-2.5 text-right',
                                'italic text-amber-600 dark:text-amber-400' => $row['data']['used'] === null,
                                'text-zinc-700 dark:text-zinc-200' => $row['data']['used'] !== null,
                            ])>{{ $row['data']['used_label'] }}</td>
                            <td @class([
                                'px-3 py-2.5 text-right',
                                'italic text-amber-600 dark:text-amber-400' => $row['data']['encashed'] === null,
                                'text-zinc-700 dark:text-zinc-200' => $row['data']['encashed'] !== null,
                            ])>{{ $row['data']['encashed_label'] }}</td>
                            <td class="px-5 py-2.5">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold {{ $classes }}">{{ $label }}</span>
                                @if($row['errors'])
                                    <ul class="mt-1 space-y-0.5 text-[11px] text-zinc-500">
                                        @foreach($row['errors'] as $error)
                                            <li>{{ $error }}</li>
                                        @endforeach
                                    </ul>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="px-5 py-8 text-center text-sm text-zinc-400">Nothing matches this filter.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="flex items-center justify-between gap-3">
            <p class="text-xs text-zinc-400">
                Only <b>Known</b> and <b>Awaiting HR Decision</b> rows are written. Duplicates, conflicts and invalid
                rows are skipped.
            </p>
            <flux:button wire:click="runImport" variant="primary" icon="arrow-up-tray"
                wire:loading.attr="disabled" wire:target="runImport"
                :disabled="(($summary['known'] ?? 0) + ($summary['awaiting_hr_decision'] ?? 0)) === 0">
                <span wire:loading.remove wire:target="runImport">Import balances</span>
                <span wire:loading wire:target="runImport">Importing…</span>
            </flux:button>
        </div>
    @endif

</flux:main>
