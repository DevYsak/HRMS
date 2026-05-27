<flux:main class="min-h-screen bg-zinc-50 dark:bg-zinc-950 p-6 space-y-6">

    {{-- â”€â”€â”€ PAGE HEADER â”€â”€â”€ --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-zinc-900 dark:text-white">Attendance Settings</h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Configure global rules, shifts, and geo-fencing locations.</p>
        </div>
        <flux:button wire:click="save" variant="primary" icon="check">Save Global Settings</flux:button>
    </div>

    {{-- â”€â”€â”€ ROW 1: Global Rules + Geo-fencing â”€â”€â”€ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">

        {{-- Global Rules --}}
        <div class="rounded-2xl border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
            <div class="mb-5 flex items-center gap-2">
                <div class="rounded-lg bg-sky-50 p-1.5 dark:bg-sky-900/20">
                    <flux:icon.cog-6-tooth class="size-4 text-sky-600 dark:text-sky-400" />
                </div>
                <h3 class="text-sm font-black text-zinc-900 dark:text-white">General Rules</h3>
            </div>

            <div class="mb-4 rounded-xl border border-zinc-100 bg-zinc-50 p-3 text-xs text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50">
                <flux:icon.information-circle class="mb-0.5 inline size-3.5 text-zinc-400" />
                These are <strong>fallback</strong> times for employees with no shift assigned.
                Per-employee timing is controlled by the <strong>Shift Management</strong> section below.
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="settings.shift_start" type="time" label="Default Start" />
                    <flux:input wire:model="settings.shift_end" type="time" label="Default End" />
                </div>
                <div class="space-y-3 pt-2">
                    <flux:switch wire:model="settings.requires_location" label="Require Geolocation"
                        description="Block clock-in if location is not shared." />
                    <flux:switch wire:model="settings.requires_qr" label="Require QR Scan"
                        description="Require scanning a physical QR code at the office." />
                </div>
            </div>
        </div>

        {{-- Geo-fencing Locations --}}
        <div class="space-y-4 lg:col-span-2">
            <div class="rounded-2xl border border-zinc-100 bg-white p-6 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mb-5 flex items-center gap-2">
                    <div class="rounded-lg bg-sky-50 p-1.5 dark:bg-sky-900/20">
                        <flux:icon.map-pin class="size-4 text-sky-600 dark:text-sky-400" />
                    </div>
                    <h3 class="text-sm font-black text-zinc-900 dark:text-white">Office Geo-fencing</h3>
                </div>

                <div class="space-y-4">
                    @foreach($offices as $office)
                        <div class="rounded-xl border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/30"
                            x-data="{ id: {{ $office->id }}, lat: '{{ $office->latitude }}', lng: '{{ $office->longitude }}', radius: '{{ $office->radius }}' }">
                            <div class="mb-4 flex items-start justify-between">
                                <div>
                                    <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $office->name }}</div>
                                    <div class="text-xs text-zinc-400">{{ $office->city }}, {{ $office->country }}</div>
                                </div>
                                <flux:button x-on:click="$wire.updateOffice(id, lat, lng, radius)" size="sm" variant="ghost" icon="arrow-path">
                                    Update
                                </flux:button>
                            </div>
                            <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                                <flux:input x-model="lat" label="Latitude" placeholder="0.000000" />
                                <flux:input x-model="lng" label="Longitude" placeholder="0.000000" />
                                <flux:input x-model="radius" label="Radius (meters)" placeholder="200" />
                            </div>
                            <div class="mt-3 flex items-center gap-1.5 text-[11px] text-zinc-400">
                                <flux:icon.information-circle class="size-3.5 shrink-0" />
                                Employees at this office must be within this radius to clock in.
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>

    {{-- â”€â”€â”€ ROW 2: Shift Management â”€â”€â”€ --}}
    <div class="rounded-2xl border border-zinc-100 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">

        {{-- Section header --}}
        <div class="flex items-center justify-between border-b border-zinc-50 px-6 py-4 dark:border-zinc-800">
            <div class="flex items-center gap-3">
                <div class="rounded-lg bg-violet-50 p-1.5 dark:bg-violet-900/20">
                    <flux:icon.clock class="size-4 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <h3 class="text-sm font-black text-zinc-900 dark:text-white">Shift Management</h3>
                    <p class="text-[11px] text-zinc-400">Define named shifts â€” each employee is assigned one, which controls their late-detection time.</p>
                </div>
            </div>
            <flux:button wire:click="openNewShift" variant="primary" icon="plus" size="sm">New Shift</flux:button>
        </div>

        {{-- How it works callout --}}
        <div class="mx-6 mt-4 rounded-xl border border-blue-100 bg-blue-50 p-4 dark:border-blue-900/40 dark:bg-blue-950/20">
            <div class="flex gap-3">
                <flux:icon.light-bulb class="mt-0.5 size-4 shrink-0 text-blue-600 dark:text-blue-400" />
                <div class="text-xs leading-relaxed text-blue-700 dark:text-blue-300">
                    <strong>How shift detection works:</strong>
                    When an employee clocks in, the system reads their assigned shift's <strong>Start Time</strong>.
                    If clock-in time is beyond <strong>Start Time + Grace Period</strong>, they are flagged <span class="font-black text-amber-600">LATE</span>.<br>
                    <span class="mt-1 block">
                        Example â€” <strong>UK Sales Shift</strong> starts at 1:00 PM with 5 min grace â†’
                        clocking in at 1:06 PM or later = <span class="font-black text-amber-600">LATE</span>.
                    </span>
                    Assign a shift to an employee in <strong>Employee Management â†’ Edit Employee â†’ Shift</strong>.
                </div>
            </div>
        </div>

        {{-- Shifts table --}}
        <div class="mt-4 overflow-x-auto">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-zinc-50 bg-zinc-50/50 dark:border-zinc-800/50 dark:bg-zinc-950/30">
                        <th class="py-3 pl-6 pr-4 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Shift Name</th>
                        <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Start Time</th>
                        <th class="px-4 py-3 text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">End Time</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Grace</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Std Hrs</th>
                        <th class="px-4 py-3 text-center text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Employees</th>
                        <th class="py-3 pr-6 text-right text-[10px] font-black uppercase tracking-[0.12em] text-zinc-400">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-50 dark:divide-zinc-800/30">
                    @forelse($shifts as $shift)
                        <tr class="transition-colors hover:bg-zinc-50/60 dark:hover:bg-zinc-800/20">
                            <td class="py-4 pl-6 pr-4">
                                <div class="text-sm font-bold text-zinc-900 dark:text-white">{{ $shift->name }}</div>
                                @if($shift->description)
                                    <div class="text-[11px] text-zinc-400">{{ $shift->description }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-4">
                                <div class="inline-flex items-center gap-1.5 rounded-lg bg-emerald-50 px-2.5 py-1 text-xs font-black text-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-400">
                                    <flux:icon.arrow-right-circle class="size-3" />
                                    {{ \Illuminate\Support\Carbon::parse($shift->start_time)->format('g:i A') }}
                                </div>
                            </td>
                            <td class="px-4 py-4">
                                <div class="inline-flex items-center gap-1.5 rounded-lg bg-zinc-50 px-2.5 py-1 text-xs font-bold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                    <flux:icon.arrow-left-circle class="size-3" />
                                    {{ \Illuminate\Support\Carbon::parse($shift->end_time)->format('g:i A') }}
                                </div>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] font-black text-amber-700 dark:bg-amber-900/20 dark:text-amber-400">
                                    <flux:icon.clock class="size-3" />
                                    {{ $shift->grace_minutes }}m
                                </span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="text-sm font-black text-zinc-900 dark:text-white">{{ (float) $shift->standard_hours }}h</span>
                            </td>
                            <td class="px-4 py-4 text-center">
                                <span class="inline-flex size-7 items-center justify-center rounded-full bg-zinc-100 text-xs font-black text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ $shift->employees_count }}
                                </span>
                            </td>
                            <td class="py-4 pr-6 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <flux:button wire:click="editShift({{ $shift->id }})" size="sm" variant="ghost" icon="pencil-square">
                                        Edit
                                    </flux:button>
                                    <flux:button
                                        wire:click="deleteShift({{ $shift->id }})"
                                        wire:confirm="Delete '{{ $shift->name }}'? This cannot be undone."
                                        size="sm" variant="ghost"
                                        class="text-rose-500 hover:text-rose-700">
                                        Delete
                                    </flux:button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="py-14 text-center">
                                <div class="flex flex-col items-center gap-3">
                                    <div class="flex size-14 items-center justify-center rounded-2xl bg-zinc-50 dark:bg-zinc-800">
                                        <flux:icon.clock class="size-7 text-zinc-300 dark:text-zinc-600" />
                                    </div>
                                    <div>
                                        <p class="text-sm font-bold text-zinc-400">No shifts configured yet.</p>
                                        <p class="mt-0.5 text-xs text-zinc-300 dark:text-zinc-600">Click "New Shift" to add IT Shift, UK Sales Shift, etc.</p>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="h-4"></div>
    </div>

    {{-- â”€â”€â”€ SHIFT CREATE / EDIT MODAL â”€â”€â”€ --}}
    <flux:modal wire:model.self="showShiftModal" class="w-full max-w-lg">
        <div class="space-y-6">
            <div class="flex items-start gap-3">
                <div class="shrink-0 rounded-xl bg-violet-50 p-2.5 dark:bg-violet-900/20">
                    <flux:icon.clock class="size-5 text-violet-600 dark:text-violet-400" />
                </div>
                <div>
                    <flux:heading size="lg">{{ $editingShiftId ? 'Edit Shift' : 'New Shift' }}</flux:heading>
                    <flux:subheading>
                        {{ $editingShiftId ? 'Update the shift timing and grace period.' : 'Define a new named shift for your team.' }}
                    </flux:subheading>
                </div>
            </div>

            <div class="space-y-4">
                <flux:input wire:model="shiftName" label="Shift Name" placeholder="e.g. IT Shift, UK Sales Shift" required />

                <div class="grid grid-cols-2 gap-4">
                    <flux:input wire:model="shiftStart" type="time" label="Start Time" required />
                    <flux:input wire:model="shiftEnd" type="time" label="End Time" required />
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>Grace Period (minutes)</flux:label>
                        <flux:input wire:model="shiftGrace" type="number" min="0" max="60" suffix="mins" required />
                        <flux:description>Clock-in allowed within this buffer after Start Time without being marked Late.</flux:description>
                    </flux:field>
                    <flux:field>
                        <flux:label>Standard Hours</flux:label>
                        <flux:input wire:model="shiftStandardHours" type="number" step="0.5" min="1" max="24" suffix="hrs" required />
                        <flux:description>Expected net work hours per day (used for OT calculation).</flux:description>
                    </flux:field>
                </div>

                <flux:input wire:model="shiftDescription" label="Description (optional)" placeholder="e.g. UK sales team aligned to BST business hours" />

                {{-- Live preview --}}
                @if($shiftStart && $shiftEnd)
                    <div class="rounded-xl border border-violet-100 bg-violet-50 p-3 dark:border-violet-900/40 dark:bg-violet-950/20">
                        <p class="text-xs font-bold text-violet-700 dark:text-violet-300">
                            <flux:icon.eye class="inline size-3.5 mr-1" />
                            Preview: Clock-in deadline =
                            <span class="font-black">
                                {{ \Illuminate\Support\Carbon::parse($shiftStart)->addMinutes((int)$shiftGrace)->format('g:i A') }}
                            </span>
                            ({{ $shiftStart }} + {{ $shiftGrace }} min grace)
                        </p>
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-3 pt-2">
                <flux:modal.close>
                    <flux:button variant="ghost">Cancel</flux:button>
                </flux:modal.close>
                <flux:button wire:click="saveShift" variant="primary">
                    {{ $editingShiftId ? 'Update Shift' : 'Create Shift' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

</flux:main>
