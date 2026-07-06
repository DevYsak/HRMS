<flux:main class="space-y-6 p-4 md:p-6">

    @php
        $selectClass = 'w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100';
        $labelClass = 'mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400';
    @endphp

    <div>
        <flux:heading size="xl">Bulk Leave Assignment</flux:heading>
        <flux:subheading>Assign, increase, decrease or reset leave balances for many employees at once.</flux:subheading>
    </div>

    {{-- Filters --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-bold text-zinc-900 dark:text-white">1 · Filter employees</h3>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-orange-50 px-3 py-1 text-xs font-bold text-orange-600 dark:bg-orange-900/20 dark:text-orange-400">
                {{ $matchCount }} matched
            </span>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <x-clean-select model="department_id" label="Department" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All']], $departments->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all())" />
            </div>
            <div>
                <x-clean-select model="job_title_id" label="Designation" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All']], $jobTitles->map(fn ($j) => ['value' => $j->id, 'label' => $j->name])->all())" />
            </div>
            <div>
                <x-clean-select model="shift_id" label="Shift" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All']], $shifts->map(fn ($s) => ['value' => $s->id, 'label' => $s->name])->all())" />
            </div>
            <div>
                <x-clean-select model="office_id" label="Branch / Office" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All']], $offices->map(fn ($o) => ['value' => $o->id, 'label' => $o->name])->all())" />
            </div>
            <div>
                <x-clean-select model="employment_type_id" label="Employment Type" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All']], $employmentTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
            </div>
            <div>
                <x-clean-select model="status" label="Status" :live="true"
                    :options="array_merge([['value' => '', 'label' => 'All']], collect($statuses)->map(fn ($st) => ['value' => $st->value, 'label' => $st->label()])->all())" />
            </div>
            <div>
                <label class="{{ $labelClass }}">Joined from</label>
                <input type="date" wire:model.live="joined_from" class="{{ $selectClass }}">
            </div>
            <div>
                <label class="{{ $labelClass }}">Joined to</label>
                <input type="date" wire:model.live="joined_to" class="{{ $selectClass }}">
            </div>
        </div>
    </div>

    {{-- Operation --}}
    <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <h3 class="mb-4 text-sm font-bold text-zinc-900 dark:text-white">2 · Choose operation</h3>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <x-clean-select model="leave_type_id" label="Leave type" placeholder="Select…" :live="false"
                    :options="$leaveTypes->map(fn ($lt) => ['value' => $lt->id, 'label' => $lt->name])->all()" />
                @error('leave_type_id')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <x-clean-select model="action" label="Action" :live="true"
                    :options="[['value' => 'assign', 'label' => 'Set to (assign)'], ['value' => 'increase', 'label' => 'Increase by'], ['value' => 'decrease', 'label' => 'Decrease by'], ['value' => 'reset', 'label' => 'Reset to default']]" />
            </div>
            <div>
                <label class="{{ $labelClass }}">Days</label>
                <input type="number" step="0.5" min="0.5" wire:model="days" @disabled($action === 'reset')
                    class="{{ $selectClass }} disabled:opacity-40" placeholder="{{ $action === 'reset' ? 'n/a' : 'e.g. 12' }}">
                @error('days')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="{{ $labelClass }}">Reason</label>
                <input type="text" wire:model="reason" class="{{ $selectClass }}" placeholder="Optional">
            </div>
        </div>

        <div class="mt-4 flex items-center gap-2">
            <flux:button wire:click="preview" variant="ghost" icon="eye">Preview</flux:button>
            <flux:button wire:click="apply" variant="primary" icon="check"
                wire:confirm="Apply this leave change to {{ $matchCount }} employee(s)?">Apply to {{ $matchCount }}</flux:button>
        </div>
        <p class="mt-2 text-[11px] text-zinc-400">
            <b>Decrease</b> never drops a balance below days already used. <b>Reset</b> restores the leave type's default allocation. System-controlled types are skipped.
        </p>
    </div>

    {{-- Preview --}}
    @if($showPreview)
        <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <div class="border-b border-zinc-100 bg-zinc-50/70 px-5 py-3 dark:border-white/5 dark:bg-zinc-800/40">
                <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Preview · {{ count($previewRows) }} employees</h3>
            </div>
            @if(empty($previewRows))
                <p class="px-5 py-8 text-center text-sm text-zinc-400">No employees match the filters.</p>
            @else
                <div class="max-h-[420px] overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="sticky top-0 bg-white dark:bg-zinc-900">
                            <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                                <th class="px-5 py-2 text-left">Employee</th>
                                <th class="px-3 py-2 text-right">Current</th>
                                <th class="px-3 py-2 text-right">New</th>
                                <th class="px-5 py-2 text-right">Change</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                            @foreach($previewRows as $row)
                                <tr>
                                    <td class="px-5 py-2 text-zinc-800 dark:text-zinc-200">{{ $row['employee']->user?->name ?? 'Employee #'.$row['employee']->id }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums text-zinc-500">{{ rtrim(rtrim(number_format($row['current'], 2), '0'), '.') }}</td>
                                    <td class="px-3 py-2 text-right tabular-nums font-semibold text-zinc-900 dark:text-white">{{ rtrim(rtrim(number_format($row['new'], 2), '0'), '.') }}</td>
                                    <td class="px-5 py-2 text-right tabular-nums font-bold {{ $row['delta'] > 0 ? 'text-emerald-600' : ($row['delta'] < 0 ? 'text-rose-500' : 'text-zinc-400') }}">
                                        {{ $row['delta'] > 0 ? '+' : '' }}{{ rtrim(rtrim(number_format($row['delta'], 2), '0'), '.') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

</flux:main>
