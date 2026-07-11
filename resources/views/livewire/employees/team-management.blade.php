<flux:main class="space-y-6 p-4 md:p-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading size="xl">Teams</flux:heading>
            <flux:subheading>Organise employees into teams under each department, with a lead and a backup approver.</flux:subheading>
        </div>
        <div class="flex items-center gap-2">
            <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search teams…" size="sm" class="w-52" />
            <flux:button wire:click="newTeam" variant="primary" icon="plus">New team</flux:button>
        </div>
    </div>

    {{-- Teams grouped by department --}}
    @php $byDept = $teams->groupBy(fn ($t) => $t->department?->name ?? 'Unassigned'); @endphp
    @forelse($byDept as $deptName => $deptTeams)
        <div>
            <div class="mb-2 flex items-center gap-2 text-xs font-bold uppercase tracking-widest text-zinc-400">
                <flux:icon.building-office-2 class="size-3.5" /> {{ $deptName }}
            </div>
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                @foreach($deptTeams as $team)
                    <div class="rounded-2xl border border-zinc-200/70 dark:border-zinc-800 bg-white dark:bg-zinc-900 p-4 shadow-sm transition hover:shadow-md">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <div class="flex items-center gap-2">
                                    <span class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $team->name }}</span>
                                    @if($team->status !== 'active')<span class="rounded-full bg-zinc-100 px-2 py-0.5 text-[9px] font-bold uppercase text-zinc-500 dark:bg-zinc-800">Inactive</span>@endif
                                </div>
                                <div class="mt-0.5 text-[11px] text-zinc-400">{{ $team->active_memberships_count }} {{ \Illuminate\Support\Str::plural('member', $team->active_memberships_count) }}</div>
                            </div>
                            <flux:button wire:click="editTeam({{ $team->id }})" size="xs" variant="ghost" icon="pencil-square">Edit</flux:button>
                        </div>
                        <div class="mt-3 space-y-1.5 border-t border-zinc-100 dark:border-zinc-800 pt-3 text-xs">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex size-5 items-center justify-center rounded-md bg-orange-50 text-orange-500"><flux:icon.star class="size-3" /></span>
                                <span class="text-zinc-400">Lead</span>
                                <span class="ml-auto font-semibold text-zinc-700 dark:text-zinc-200">{{ $team->teamLead?->user?->name ?? '— none —' }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="inline-flex size-5 items-center justify-center rounded-md bg-sky-50 text-sky-500"><flux:icon.user class="size-3" /></span>
                                <span class="text-zinc-400">Backup</span>
                                <span class="ml-auto font-semibold text-zinc-500 dark:text-zinc-400">{{ $team->secondaryLead?->user?->name ?? '—' }}</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div class="rounded-2xl border border-dashed border-zinc-300 dark:border-zinc-700 py-16 text-center">
            <flux:icon.user-group class="mx-auto mb-2 size-8 text-zinc-300" />
            <p class="text-sm text-zinc-400">No teams yet. Create the first team to start organising employees.</p>
        </div>
    @endforelse

    {{-- Create / edit --}}
    <flux:modal wire:model.self="showForm" class="w-full max-w-lg">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit team' : 'New team' }}</flux:heading>

            <flux:input wire:model="name" label="Team name" placeholder="e.g. Web Team" required />

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-clean-select model="departmentId" label="Department" :required="true" placeholder="Select…" :live="true"
                    :options="$departments->map(fn ($d) => ['value' => $d->id, 'label' => $d->name])->all()" />
                <x-clean-select model="status" label="Status" :live="false"
                    :options="[['value' => 'active', 'label' => 'Active'], ['value' => 'inactive', 'label' => 'Inactive']]" />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-clean-select model="teamLeadId" label="Team lead" placeholder="— none —" :live="false"
                    :options="$employees->map(fn ($e) => ['value' => $e->id, 'label' => $e->user?->name ?? ('Emp '.$e->id)])->all()" />
                <x-clean-select model="secondaryLeadId" label="Secondary lead (backup)" placeholder="— none —" :live="false"
                    :options="$employees->map(fn ($e) => ['value' => $e->id, 'label' => $e->user?->name ?? ('Emp '.$e->id)])->all()" />
            </div>
            @error('secondaryLeadId')<p class="text-xs text-rose-500">{{ $message }}</p>@enderror

            <div>
                <div class="mb-1.5 text-sm font-medium text-zinc-700 dark:text-zinc-200">Members <span class="text-xs font-normal text-zinc-400">({{ count($memberIds) }} selected — moving here ends their current team)</span></div>
                <div class="max-h-52 space-y-1 overflow-y-auto rounded-xl border border-zinc-200 dark:border-zinc-800 p-2">
                    @forelse($employees as $emp)
                        <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <input type="checkbox" wire:model="memberIds" value="{{ $emp->id }}" class="rounded border-zinc-300 text-orange-500 focus:ring-orange-400">
                            <span class="text-zinc-700 dark:text-zinc-200">{{ $emp->user?->name ?? ('Emp '.$emp->id) }}</span>
                        </label>
                    @empty
                        <p class="px-2 py-4 text-center text-xs text-zinc-400">Pick a department to list its employees.</p>
                    @endforelse
                </div>
            </div>

            <div class="flex justify-end gap-2 border-t border-zinc-100 dark:border-zinc-800 pt-3">
                <flux:button type="button" wire:click="$set('showForm', false)" variant="ghost">Cancel</flux:button>
                <flux:button type="submit" variant="primary" icon="check">{{ $editingId ? 'Save changes' : 'Create team' }}</flux:button>
            </div>
        </form>
    </flux:modal>
</flux:main>
