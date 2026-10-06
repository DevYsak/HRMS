<flux:main class="space-y-6 p-4 md:p-6">

    <div>
        <flux:heading size="xl">Coordinators</flux:heading>
        <flux:subheading>Choose who each coordinator monitors. Coordinators see absences, late arrivals, missing check-outs and pending regularisations for those people, remind them and escalate to the manager or HR. They cannot approve anything or change pay or settings.</flux:subheading>
    </div>

    <div class="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm lg:col-span-2 dark:border-white/10 dark:bg-zinc-900">
            <select wire:model.live="coordinatorId" aria-label="Coordinator" class="w-full rounded-xl border border-zinc-200 bg-white py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                <option value="">Choose a coordinator…</option>
                @foreach($coordinators as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
            @if($coordinators->isEmpty())
                <p class="text-xs text-zinc-500">Nobody holds the Coordinator role yet — give it to someone on their employee record.</p>
            @endif
            @error('coordinatorId')<p class="text-xs text-rose-600">{{ $message }}</p>@enderror

            @if($coordinatorId)
                <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                    <div class="flex items-end gap-2">
                        <select wire:model="addDepartmentId" aria-label="Department" class="min-w-0 flex-1 rounded-xl border border-zinc-200 bg-white py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <option value="">Add a department…</option>
                            @foreach($departments as $d)<option value="{{ $d->id }}">{{ $d->name }}</option>@endforeach
                        </select>
                        <flux:button size="sm" wire:click="assign('department')">Add</flux:button>
                    </div>
                    <div class="flex items-end gap-2">
                        <select wire:model="addEmployeeId" aria-label="Employee" class="min-w-0 flex-1 rounded-xl border border-zinc-200 bg-white py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                            <option value="">Add an employee…</option>
                            @foreach($employees as $e)<option value="{{ $e->id }}">{{ $e->user?->name }}</option>@endforeach
                        </select>
                        <flux:button size="sm" wire:click="assign('employee')">Add</flux:button>
                    </div>
                </div>
            @endif

            <div class="divide-y divide-zinc-100 dark:divide-white/5">
                @forelse($assignments as $a)
                    <div wire:key="ca-{{ $a->id }}" class="flex items-center justify-between gap-2 py-2 text-sm">
                        <div>
                            <span class="font-medium text-zinc-800 dark:text-zinc-100">{{ $a->department?->name ?? $a->employee?->user?->name }}</span>
                            <span class="text-xs text-zinc-400">· {{ $a->department_id ? 'department' : 'employee' }} · {{ $a->coordinator?->name }}</span>
                        </div>
                        <button type="button" wire:click="unassign({{ $a->id }})" wire:confirm="Stop monitoring this?" class="text-xs font-semibold text-rose-600 hover:underline">Remove</button>
                    </div>
                @empty
                    <p class="py-6 text-center text-sm text-zinc-400">No assignments yet.</p>
                @endforelse
            </div>
        </div>

        <form wire:submit="saveThresholds" class="space-y-3 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-zinc-900">
            <h3 class="text-xs font-bold uppercase tracking-widest text-zinc-500">Alerts</h3>
            <flux:input type="number" min="1" max="24" wire:model="reminderHours" label="Remind about unresolved issues every (hours)" />
            <flux:input type="number" min="0" max="240" wire:model="lateMinutes" label="Alert when late by more than (minutes beyond grace)" />
            <flux:button type="submit" variant="primary" size="sm">Save</flux:button>
        </form>
    </div>

</flux:main>
