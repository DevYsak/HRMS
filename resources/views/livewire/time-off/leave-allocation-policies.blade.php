<flux:main class="space-y-6 p-4 md:p-6">

    @php
        $selectClass = 'w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-800 shadow-sm focus:border-orange-400 focus:ring-orange-400 dark:border-white/10 dark:bg-zinc-900 dark:text-zinc-100';
        $labelClass = 'mb-1 block text-[11px] font-bold uppercase tracking-wider text-zinc-400';
    @endphp

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Leave Policies</flux:heading>
            <flux:subheading>Default leave allocations by department, role, gender, employment type or service — applied automatically on hire.</flux:subheading>
        </div>
        <flux:button wire:click="openCreate" variant="primary" icon="plus">New policy</flux:button>
    </div>

    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-white/10 dark:bg-zinc-900">
        <table class="w-full text-sm">
            <thead class="border-b border-zinc-100 bg-zinc-50/70 dark:border-white/5 dark:bg-zinc-800/40">
                <tr class="text-[10px] font-bold uppercase tracking-wider text-zinc-400">
                    <th class="px-5 py-3 text-left">Leave type</th>
                    <th class="px-3 py-3 text-right">Days</th>
                    <th class="px-3 py-3 text-left">Applies to</th>
                    <th class="px-3 py-3 text-center">Active</th>
                    <th class="px-5 py-3 text-right"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($policies as $p)
                    @php
                        $conds = collect([
                            $p->department?->name,
                            $p->jobTitle?->name,
                            $p->employmentType?->name,
                            $p->gender ? ucfirst($p->gender) : null,
                            $p->role ? \Illuminate\Support\Str::headline($p->role) : null,
                            $p->min_service_months > 0 ? $p->min_service_months.'+ mo service' : null,
                            $p->requires_probation_complete ? 'post-probation' : null,
                        ])->filter()->values();
                    @endphp
                    <tr class="transition hover:bg-zinc-50/60 dark:hover:bg-zinc-800/30">
                        <td class="px-5 py-3 font-semibold text-zinc-900 dark:text-white">{{ $p->leaveType?->name ?? '—' }}</td>
                        <td class="px-3 py-3 text-right font-bold tabular-nums text-zinc-900 dark:text-white">{{ rtrim(rtrim(number_format((float) $p->allocated_days, 2), '0'), '.') }}</td>
                        <td class="px-3 py-3">
                            @if($conds->isEmpty())
                                <span class="text-xs text-zinc-400">Everyone (default)</span>
                            @else
                                <div class="flex flex-wrap gap-1">
                                    @foreach($conds as $c)
                                        <span class="inline-flex rounded-md bg-zinc-100 px-1.5 py-0.5 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">{{ $c }}</span>
                                    @endforeach
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-3 text-center">
                            <span @class([
                                'inline-flex rounded-full px-2 py-0.5 text-[10px] font-bold',
                                'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/30 dark:text-emerald-400' => $p->is_active,
                                'bg-zinc-100 text-zinc-500 dark:bg-zinc-800' => ! $p->is_active,
                            ])>{{ $p->is_active ? 'Active' : 'Off' }}</span>
                        </td>
                        <td class="px-5 py-3 text-right">
                            <flux:button wire:click="openEdit({{ $p->id }})" size="xs" variant="ghost" icon="pencil-square">Edit</flux:button>
                            <flux:button wire:click="delete({{ $p->id }})" wire:confirm="Delete this policy?" size="xs" variant="ghost" icon="trash" class="text-rose-500">Delete</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-5 py-12 text-center text-sm text-zinc-400">
                            No policies yet. Leave types fall back to their uniform default allocation.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Create / edit modal --}}
    <flux:modal wire:model.self="showModal" class="w-full max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ $editingId ? 'Edit' : 'New' }} leave policy</flux:heading>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <x-clean-select model="leave_type_id" label="Leave type" :required="true" placeholder="Select…" :live="false"
                        :options="$leaveTypes->map(fn ($lt) => ['value' => $lt->id, 'label' => $lt->name])->all()" />
                    @error('leave_type_id')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                </div>
                <div>
                    <label class="{{ $labelClass }}">Allocated days *</label>
                    <input type="number" step="0.5" min="0" wire:model="allocated_days" class="{{ $selectClass }}" placeholder="e.g. 18">
                    @error('allocated_days')<p class="mt-1 text-xs text-rose-500">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="rounded-xl border border-zinc-100 p-3 dark:border-white/5">
                <p class="mb-3 text-[11px] font-bold uppercase tracking-wider text-zinc-400">Conditions (leave blank for “anyone”)</p>
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div>
                        <x-clean-select model="department_id" label="Department" :live="false"
                            :options="array_merge([['value' => '', 'label' => 'Any']], $departments->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all())" />
                    </div>
                    <div>
                        <x-clean-select model="job_title_id" label="Designation" :live="false"
                            :options="array_merge([['value' => '', 'label' => 'Any']], $jobTitles->map(fn ($j) => ['value' => $j->id, 'label' => $j->name])->all())" />
                    </div>
                    <div>
                        <x-clean-select model="employment_type_id" label="Employment type" :live="false"
                            :options="array_merge([['value' => '', 'label' => 'Any']], $employmentTypes->map(fn ($t) => ['value' => $t->id, 'label' => $t->name])->all())" />
                    </div>
                    <div>
                        <x-clean-select model="role" label="Role" :live="false"
                            :options="array_merge([['value' => '', 'label' => 'Any']], collect($roles)->map(fn ($r) => ['value' => $r->value, 'label' => $r->label()])->all())" />
                    </div>
                    <div>
                        <x-clean-select model="gender" label="Gender" :live="false"
                            :options="[['value' => '', 'label' => 'Any'], ['value' => 'male', 'label' => 'Male'], ['value' => 'female', 'label' => 'Female'], ['value' => 'other', 'label' => 'Other']]" />
                    </div>
                    <div>
                        <label class="{{ $labelClass }}">Min. service (months)</label>
                        <input type="number" min="0" wire:model="min_service_months" class="{{ $selectClass }}">
                    </div>
                </div>
                <label class="mt-3 flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                    <input type="checkbox" wire:model="requires_probation_complete" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                    Only after probation is complete
                </label>
            </div>

            <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-300">
                <input type="checkbox" wire:model="is_active" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                Active
            </label>

            <div class="flex justify-end gap-2">
                <flux:button wire:click="$set('showModal', false)" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="save" variant="primary">Save policy</flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
