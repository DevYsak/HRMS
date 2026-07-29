<flux:main class="bg-zinc-50 p-6 space-y-6 dark:bg-zinc-950">
    <div class="pulse-page-header">
        <div>
            <h1 class="pulse-page-title">Historical Payroll Import</h1>
            <p class="pulse-page-subtitle">Bulk-import pre-Pulse payslip history from a spreadsheet — the figures you upload are recorded as-is, nothing is recalculated.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="downloadTemplate" icon="arrow-down-tray" variant="ghost" size="sm">Sample Excel</flux:button>
            <flux:button wire:click="downloadTemplateCsv" icon="arrow-down-tray" variant="ghost" size="sm">Sample CSV</flux:button>
        </div>
    </div>

    {{-- Upload --}}
    <div class="pulse-card p-5">
        <h3 class="mb-4 text-sm font-bold text-zinc-900 dark:text-white">1 &middot; Upload &amp; validate</h3>

        <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">File (.xlsx, .xls, .csv)</label>
        <input type="file" wire:model="file" accept=".xlsx,.xls,.csv,.txt"
            class="block w-full max-w-xl rounded-xl border border-zinc-200 bg-white text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-violet-500 file:px-4 file:py-2 file:font-bold file:text-white hover:file:bg-violet-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-200">
        @error('file')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
        <div wire:loading wire:target="file" class="mt-1 text-xs text-zinc-400">Uploading&hellip;</div>

        <p class="mt-3 text-xs text-zinc-500">
            Match employees by <strong>Employee ID</strong> or <strong>Email</strong>. Rows for an employee/month/year that already has a payslip are skipped.
            Provide named line items (Basic, HRA, PF, TDS&hellip;) for a full breakdown, or just Gross/Deductions/Net for a quick entry.
        </p>

        <div class="mt-4">
            <flux:button wire:click="analyze" variant="primary" icon="magnifying-glass"
                wire:loading.attr="disabled" wire:target="analyze,file">
                <span wire:loading.remove wire:target="analyze">Analyze file</span>
                <span wire:loading wire:target="analyze">Analyzing&hellip;</span>
            </flux:button>
        </div>
    </div>

    {{-- Result summary --}}
    @if($lastResult)
        <div class="grid grid-cols-3 gap-3 max-w-xl">
            <div class="pulse-card p-4 text-center">
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400">{{ $lastResult['imported'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Added</div>
            </div>
            <div class="pulse-card p-4 text-center">
                <div class="text-2xl font-black text-amber-600 dark:text-amber-400">{{ $lastResult['skipped'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Skipped</div>
            </div>
            <div class="pulse-card p-4 text-center">
                <div class="text-2xl font-black text-rose-600 dark:text-rose-400">{{ $lastResult['failed'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Failed</div>
            </div>
        </div>
    @endif

    {{-- Preview --}}
    @if($showPreview)
        @php $summary = $parsed['summary'] ?? ['total'=>0,'new'=>0,'duplicate'=>0,'error'=>0]; @endphp
        <div class="pulse-card p-0 overflow-hidden">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-zinc-800/60 dark:bg-zinc-900/40">
                <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">2 &middot; Preview &middot; {{ $summary['total'] }} rows</h3>
                <div class="flex items-center gap-3 text-xs">
                    <span class="font-bold text-emerald-600">{{ $summary['new'] }} new</span>
                    <span class="font-bold text-amber-600">{{ $summary['duplicate'] }} duplicate</span>
                    <span class="font-bold text-rose-500">{{ $summary['error'] }} errors</span>
                    <flux:button wire:click="runImport" variant="primary" size="sm" icon="check"
                        wire:confirm="Import this file now?"
                        wire:loading.attr="disabled" wire:target="runImport">
                        <span wire:loading.remove wire:target="runImport">Import</span>
                        <span wire:loading wire:target="runImport">Importing&hellip;</span>
                    </flux:button>
                </div>
            </div>
            <div class="max-h-[460px] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-zinc-900">
                        <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                            <th class="px-4 py-2 text-left">Row</th>
                            <th class="px-4 py-2 text-left">Employee</th>
                            <th class="px-4 py-2 text-left">Period</th>
                            <th class="px-4 py-2 text-right">Net</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">Issues</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                        @foreach($parsed['rows'] as $row)
                            <tr class="{{ $row['status'] === 'error' ? 'bg-rose-50/40 dark:bg-rose-900/10' : '' }}">
                                <td class="px-4 py-2 text-zinc-400">{{ $row['line'] }}</td>
                                <td class="px-4 py-2 font-medium text-zinc-800 dark:text-zinc-200">{{ $row['data']['employee_label'] ?: '—' }}</td>
                                <td class="px-4 py-2 text-zinc-500">{{ $row['data']['month'] }} {{ $row['data']['year'] }}</td>
                                <td class="px-4 py-2 text-right text-zinc-700 dark:text-zinc-300">{{ number_format($row['data']['net_salary'], 2) }}</td>
                                <td class="px-4 py-2">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold',
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $row['status'] === 'new',
                                        'bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400' => $row['status'] === 'duplicate',
                                        'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' => $row['status'] === 'error',
                                    ])>{{ ucfirst($row['status']) }}</span>
                                </td>
                                <td class="px-4 py-2 text-xs text-rose-500">
                                    {{ implode(' ', $row['errors']) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Recent imports --}}
    <div class="pulse-card p-0 overflow-hidden">
        <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-zinc-800/60 dark:bg-zinc-900/40">
            <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Recent imports</h3>
        </div>
        @if($recentLogs->isEmpty())
            <p class="px-5 py-8 text-center text-sm text-zinc-400">No imports yet.</p>
        @else
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                        <th class="px-5 py-2 text-left">File</th>
                        <th class="px-3 py-2 text-left">By</th>
                        <th class="px-3 py-2 text-center">Added</th>
                        <th class="px-3 py-2 text-center">Skipped</th>
                        <th class="px-3 py-2 text-center">Failed</th>
                        <th class="px-5 py-2 text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800/60">
                    @foreach($recentLogs as $log)
                        <tr>
                            <td class="px-5 py-2 text-zinc-700 dark:text-zinc-300">{{ $log->filename ?? '—' }}</td>
                            <td class="px-3 py-2 text-zinc-500">{{ $log->user?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-center font-semibold text-emerald-600">{{ $log->imported }}</td>
                            <td class="px-3 py-2 text-center text-amber-600">{{ $log->skipped }}</td>
                            <td class="px-3 py-2 text-center text-rose-500">{{ $log->failed }}</td>
                            <td class="px-5 py-2 text-right text-[11px] text-zinc-400">{{ $log->created_at?->diffForHumans() }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>
</flux:main>
