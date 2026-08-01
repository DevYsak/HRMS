<flux:main class="space-y-6 p-4 md:p-6">

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Import Employees</flux:heading>
            <flux:subheading>Bulk-create or update employees from an Excel/CSV file.</flux:subheading>
        </div>
        <div class="flex flex-wrap gap-2">
            <flux:button wire:click="downloadTemplate" icon="arrow-down-tray" variant="ghost" size="sm">Sample Excel</flux:button>
            <flux:button wire:click="downloadTemplateCsv" icon="arrow-down-tray" variant="ghost" size="sm">Sample CSV</flux:button>
            <flux:button wire:click="exportEmployees" icon="users" variant="ghost" size="sm">Export existing</flux:button>
        </div>
    </div>

    {{-- Upload --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <h3 class="mb-4 text-sm font-bold text-zinc-900 dark:text-white">1 · Upload &amp; validate</h3>

        <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <label class="mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400">File (.xlsx, .xls, .csv)</label>
                <input type="file" wire:model="file" accept=".xlsx,.xls,.csv,.txt"
                    class="block w-full rounded-xl border border-zinc-200 bg-white text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-500 file:px-4 file:py-2 file:font-bold file:text-white hover:file:bg-orange-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-200">
                @error('file')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                <div wire:loading wire:target="file" class="mt-1 text-xs text-zinc-400">Uploading…</div>
            </div>
            <div class="space-y-3">
                <div>
                    <x-clean-select model="mode" label="Existing employees" :live="false"
                        :options="[['value' => 'skip', 'label' => 'Skip (leave unchanged)'], ['value' => 'update', 'label' => 'Update from file']]" />
                </div>
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <input type="checkbox" wire:model="sendWelcome" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    Email welcome + credentials to new hires
                    <span class="text-[11px] text-zinc-400">(off by default; also respects the Welcome Email toggle)</span>
                </label>
                <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <input type="checkbox" wire:model="autoCreateMasterData" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    Create missing departments, designations &amp; shifts
                    <span class="text-[11px] text-zinc-400">(rather than importing those fields blank)</span>
                </label>
            </div>
        </div>

        <div class="mt-4">
            <flux:button wire:click="analyze" variant="primary" icon="magnifying-glass"
                wire:loading.attr="disabled" wire:target="analyze,file">
                <span wire:loading.remove wire:target="analyze">Analyze file</span>
                <span wire:loading wire:target="analyze">Analyzing…</span>
            </flux:button>
        </div>
    </div>

    {{-- Result summary --}}
    @if($lastResult)
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 text-center shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="text-2xl font-black text-emerald-600 dark:text-emerald-400">{{ $lastResult['imported'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Added</div>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 text-center shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="text-2xl font-black text-blue-600 dark:text-blue-400">{{ $lastResult['updated'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Updated</div>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 text-center shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="text-2xl font-black text-amber-600 dark:text-amber-400">{{ $lastResult['skipped'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Skipped</div>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 text-center shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <div class="text-2xl font-black text-rose-600 dark:text-rose-400">{{ $lastResult['failed'] }}</div>
                <div class="text-[10px] font-bold uppercase tracking-wide text-zinc-400">Failed</div>
            </div>
        </div>

        {{-- Post-import validation summary --}}
        <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Validation report</h3>
                <flux:button wire:click="downloadValidationReport({{ $lastResult['log_id'] }})" icon="arrow-down-tray" variant="ghost" size="sm">
                    Download report
                </flux:button>
            </div>

            <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                <div class="flex items-center gap-2">
                    <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $lastResult['total_rows'] }}</span>
                    <span class="text-zinc-600 dark:text-zinc-300">Total rows</span>
                </div>
                <div class="flex items-center gap-2">
                    <span class="font-bold text-emerald-600 dark:text-emerald-400">{{ $lastResult['imported'] + $lastResult['updated'] }}</span>
                    <span class="text-zinc-600 dark:text-zinc-300">Successfully imported</span>
                </div>
                @foreach($lastResult['quality'] ?? [] as $key => $count)
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-amber-600 dark:text-amber-400">{{ $count }}</span>
                        <span class="text-zinc-600 dark:text-zinc-300">{{ ucfirst(str_replace('_', ' ', $key)) }}</span>
                    </div>
                @endforeach
            </div>

            @if(! empty($lastResult['quality']))
                <p class="mt-3 text-xs text-zinc-500">
                    Flagged employees were still imported. The downloadable report lists exactly who needs an email address or joining date filling in.
                </p>
            @endif
        </div>
    @endif

    {{-- Preview --}}
    @if($showPreview)
        @php
            $summary = $parsed['summary'] ?? ['total'=>0,'new'=>0,'update'=>0,'error'=>0];
            $preflight = array_filter($parsed['preflight'] ?? []);
            $quality = array_filter($parsed['quality'] ?? []);
        @endphp

        {{-- Pre-flight: master data the file references but the system doesn't have yet --}}
        @if(! empty($preflight))
            <div class="rounded-2xl border p-5 {{ $autoCreateMasterData ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-900/40 dark:bg-emerald-900/10' : 'border-amber-200 bg-amber-50 dark:border-amber-900/40 dark:bg-amber-900/10' }}">
                <div class="flex items-start gap-3">
                    @if($autoCreateMasterData)
                        <flux:icon.sparkles class="mt-0.5 size-5 shrink-0 text-emerald-500" />
                    @else
                        <flux:icon.exclamation-triangle class="mt-0.5 size-5 shrink-0 text-amber-500" />
                    @endif
                    <div class="space-y-2">
                        @if($autoCreateMasterData)
                            <h3 class="text-sm font-bold text-emerald-900 dark:text-emerald-200">These will be created automatically</h3>
                            <p class="text-xs text-emerald-800 dark:text-emerald-300">
                                The file refers to records that don't exist yet. They'll be created as part of the import. Shift hours are read from the name where possible &mdash; check them afterwards in Settings.
                            </p>
                        @else
                            <h3 class="text-sm font-bold text-amber-900 dark:text-amber-200">Set these up before importing</h3>
                            <p class="text-xs text-amber-800 dark:text-amber-300">
                                The file refers to the records below, but they don't exist yet. Rows will still import &mdash; these fields will just be left blank. Tick "Create missing departments, designations &amp; shifts" above to have them made for you.
                            </p>
                        @endif
                        <ul class="space-y-1 text-xs text-amber-900 dark:text-amber-200">
                            @foreach($preflight as $label => $values)
                                <li>
                                    <span class="font-bold">{{ $label }}:</span>
                                    {{ implode(', ', array_map(fn ($v) => '"'.$v.'"', $values)) }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </div>
            </div>
        @endif

        {{-- Data quality counters --}}
        @if(! empty($quality))
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <h3 class="mb-3 text-xs font-bold uppercase tracking-widest text-zinc-500">Data quality</h3>
                <div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">
                    @foreach($quality as $key => $count)
                        <div class="flex items-center gap-2">
                            <span class="font-bold text-rose-600 dark:text-rose-400">{{ $count }}</span>
                            <span class="text-zinc-600 dark:text-zinc-300">{{ ucfirst(str_replace('_', ' ', $key)) }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
                <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">2 · Preview · {{ $summary['total'] }} rows</h3>
                <div class="flex items-center gap-3 text-xs">
                    <span class="font-bold text-emerald-600">{{ $summary['new'] }} new</span>
                    <span class="font-bold text-blue-600">{{ $summary['update'] }} to update</span>
                    <span class="font-bold text-rose-500">{{ $summary['error'] }} errors</span>
                    <flux:button wire:click="runImport" variant="primary" size="sm" icon="check"
                        wire:confirm="Import this file now?"
                        wire:loading.attr="disabled" wire:target="runImport">
                        <span wire:loading.remove wire:target="runImport">Import</span>
                        <span wire:loading wire:target="runImport">Importing…</span>
                    </flux:button>
                </div>
            </div>
            <div class="max-h-[460px] overflow-y-auto">
                <table class="w-full text-sm">
                    <thead class="sticky top-0 bg-white dark:bg-zinc-900">
                        <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                            <th class="px-4 py-2 text-left">Row</th>
                            <th class="px-4 py-2 text-left">Name</th>
                            <th class="px-4 py-2 text-left">Email</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">Issues</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                        @foreach($parsed['rows'] as $row)
                            <tr class="{{ $row['status'] === 'error' ? 'bg-rose-50/40 dark:bg-rose-900/10' : '' }}">
                                <td class="px-4 py-2 text-zinc-400">{{ $row['line'] }}</td>
                                <td class="px-4 py-2 font-medium text-zinc-800 dark:text-zinc-200">{{ $row['data']['name'] ?: '—' }}</td>
                                <td class="px-4 py-2 text-zinc-500">{{ $row['data']['email'] ?: '—' }}</td>
                                <td class="px-4 py-2">
                                    <span @class([
                                        'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold',
                                        'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $row['status'] === 'new',
                                        'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' => $row['status'] === 'update',
                                        'bg-rose-100 text-rose-700 dark:bg-rose-900/30 dark:text-rose-400' => $row['status'] === 'error',
                                    ])>{{ ucfirst($row['status']) }}</span>
                                </td>
                                <td class="px-4 py-2 text-xs">
                                    @if(! empty($row['errors']))
                                        <span class="text-rose-500">{{ implode(' ', $row['errors']) }}</span>
                                    @endif
                                    @if(! empty($row['warnings']))
                                        <span class="text-amber-600 dark:text-amber-400">{{ implode(' ', $row['warnings']) }}</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Recent imports --}}
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
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
                        <th class="px-3 py-2 text-center">Updated</th>
                        <th class="px-3 py-2 text-center">Skipped</th>
                        <th class="px-3 py-2 text-center">Failed</th>
                        <th class="px-5 py-2 text-right">When</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                    @foreach($recentLogs as $log)
                        <tr>
                            <td class="px-5 py-2 text-zinc-700 dark:text-zinc-300">{{ $log->filename ?? '—' }}</td>
                            <td class="px-3 py-2 text-zinc-500">{{ $log->user?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-center font-semibold text-emerald-600">{{ $log->imported }}</td>
                            <td class="px-3 py-2 text-center font-semibold text-blue-600">{{ $log->updated }}</td>
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
