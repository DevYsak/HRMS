<flux:main class="space-y-8 p-4 md:p-6">

    <div>
        <flux:heading size="xl">Import / Export</flux:heading>
        <flux:subheading>Download HR data as a spreadsheet, or load data in bulk after checking a preview.</flux:subheading>
    </div>

    @if($canExport)
        {{-- ── Export ─────────────────────────────────────────────────── --}}
        <section class="space-y-4">
            <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-500">
                <flux:icon.arrow-down-tray class="size-4 text-orange-400" /> Export
            </h3>

            <div class="grid grid-cols-1 gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm sm:grid-cols-4 dark:border-white/10 dark:bg-zinc-900">
                <flux:select wire:model="format" label="Format">
                    <flux:select.option value="xlsx">Excel (.xlsx)</flux:select.option>
                    <flux:select.option value="csv">CSV (.csv)</flux:select.option>
                </flux:select>
                <flux:input type="date" wire:model="from" label="From" description:trailing="Leave requests &amp; attendance" />
                <flux:input type="date" wire:model="to" label="To" />
                <flux:input type="number" wire:model="year" label="Year" description:trailing="Leave balances &amp; holidays" min="2000" max="2100" />
            </div>

            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Exports include only the employees you can see, and never bank, tax-ID or salary details. Each download is recorded in the audit log.
            </p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($datasets as $key => $dataset)
                    <div wire:key="dataset-{{ $key }}" class="flex flex-col justify-between gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                        <div>
                            <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $dataset['label'] }}</div>
                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $dataset['description'] }}</p>
                            <p class="mt-1 text-[11px] font-medium text-zinc-400">
                                @switch($dataset['filter'])
                                    @case('range') Uses From – To @break
                                    @case('year') Uses Year @break
                                    @default All records
                                @endswitch
                            </p>
                        </div>
                        <flux:button wire:click="export('{{ $key }}')" size="sm" icon="arrow-down-tray">Download</flux:button>
                    </div>
                @endforeach
            </div>
        </section>
    @endif

    @if($canImport)
        {{-- ── Import: holidays ───────────────────────────────────────── --}}
        <section class="space-y-4">
            <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-500">
                <flux:icon.arrow-up-tray class="size-4 text-orange-400" /> Import holidays
            </h3>

            <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
                <ol class="list-decimal space-y-1 pl-5 text-sm text-zinc-600 dark:text-zinc-300">
                    <li>Download the template (or export a year's holidays above and edit it).</li>
                    <li>Fill one holiday per row. Dates as <b>YYYY-MM-DD</b> or <b>DD/MM/YYYY</b>; Paid / Optional / Recurring as Yes or No; Country such as UK or IN (blank = the company calendar).</li>
                    <li>Upload it, check the preview, then import. Rows with problems and holidays that already exist are skipped — nothing existing is changed.</li>
                </ol>

                <div class="flex flex-wrap items-end gap-3">
                    <flux:button wire:click="downloadHolidayTemplate" size="sm" variant="ghost" icon="document-arrow-down">Download template</flux:button>
                    <div class="min-w-64 flex-1">
                        <input type="file" wire:model="holidayFile" accept=".xlsx,.csv,.txt"
                            class="block w-full rounded-xl border border-zinc-200 bg-white text-sm text-zinc-700 file:mr-3 file:rounded-lg file:border-0 file:bg-orange-500 file:px-4 file:py-2 file:font-bold file:text-white hover:file:bg-orange-600 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-200">
                        @error('holidayFile')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                        <div wire:loading wire:target="holidayFile" class="mt-1 text-xs text-zinc-400">Reading file…</div>
                    </div>
                </div>

                @if($holidayPreview !== [])
                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <span class="rounded-lg bg-emerald-50 px-2.5 py-1 font-bold text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300">{{ $previewValid }} ready</span>
                        @if($previewInvalid)
                            <span class="rounded-lg bg-rose-50 px-2.5 py-1 font-bold text-rose-700 dark:bg-rose-500/10 dark:text-rose-300">{{ $previewInvalid }} will be skipped</span>
                        @endif
                        <span class="text-xs text-zinc-400">{{ $holidayFileName }}</span>
                    </div>

                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-white/10">
                        <table class="min-w-full text-left text-xs">
                            <thead class="bg-zinc-50 text-[10px] uppercase tracking-wider text-zinc-500 dark:bg-zinc-800/50">
                                <tr>
                                    <th class="px-3 py-2">Row</th>
                                    <th class="px-3 py-2">Name</th>
                                    <th class="px-3 py-2">Date</th>
                                    <th class="px-3 py-2">Type</th>
                                    <th class="px-3 py-2">Country</th>
                                    <th class="px-3 py-2">Paid</th>
                                    <th class="px-3 py-2">Result</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                                @foreach($holidayPreview as $row)
                                    <tr wire:key="preview-{{ $row['line'] }}" class="{{ $row['errors'] ? 'bg-rose-50/60 dark:bg-rose-500/5' : '' }}">
                                        <td class="px-3 py-2 tabular-nums text-zinc-400">{{ $row['line'] }}</td>
                                        <td class="px-3 py-2 font-medium text-zinc-900 dark:text-white">{{ $row['data']['name'] }}</td>
                                        <td class="px-3 py-2 tabular-nums">{{ $row['data']['date'] }}</td>
                                        <td class="px-3 py-2">{{ $row['data']['holiday_type'] }}</td>
                                        <td class="px-3 py-2">{{ $row['data']['country'] }}</td>
                                        <td class="px-3 py-2">{{ $row['data']['is_paid'] ? 'Yes' : 'No' }}</td>
                                        <td class="px-3 py-2">
                                            @if($row['errors'])
                                                <span class="text-rose-600 dark:text-rose-400">{{ implode(' ', $row['errors']) }}</span>
                                            @else
                                                <span class="font-bold text-emerald-600 dark:text-emerald-400">Ready</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>

                    <div class="flex gap-2">
                        <flux:button wire:click="importHolidays" variant="primary" size="sm" icon="arrow-up-tray" :disabled="$previewValid === 0"
                            wire:confirm="Import {{ $previewValid }} holiday(s)?">
                            Import {{ $previewValid }} holiday(s)
                        </flux:button>
                        <flux:button wire:click="clearHolidayPreview" variant="ghost" size="sm">Cancel</flux:button>
                    </div>
                @endif
            </div>
        </section>
    @endif

    @if($otherImporters !== [])
        {{-- ── The specialised importers ───────────────────────────────── --}}
        <section class="space-y-4">
            <h3 class="flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-500">
                <flux:icon.squares-plus class="size-4 text-orange-400" /> Other importers
            </h3>
            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($otherImporters as $item)
                    @if(Route::has($item['route']))
                        <a href="{{ route($item['route']) }}" wire:navigate wire:key="importer-{{ $item['route'] }}"
                            class="group flex items-start gap-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:-translate-y-0.5 hover:border-orange-200 hover:shadow-md dark:border-white/10 dark:bg-zinc-900 dark:hover:border-orange-500/30">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-orange-50 text-orange-500 dark:bg-orange-900/20">
                                <flux:icon :name="$item['icon']" class="size-5" />
                            </span>
                            <div class="min-w-0">
                                <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $item['label'] }}</div>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['description'] }}</p>
                            </div>
                        </a>
                    @endif
                @endforeach
            </div>
        </section>
    @endif

</flux:main>
