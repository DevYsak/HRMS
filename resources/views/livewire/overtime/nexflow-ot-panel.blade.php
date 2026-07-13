<flux:main class="space-y-6 p-4 md:p-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading size="xl">Nexflow Overtime</flux:heading>
            <flux:subheading>Pull an employee's overtime from Nexflow and import approved hours into payroll.</flux:subheading>
        </div>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-indigo-50 px-3 py-1 text-[11px] font-bold text-indigo-600 dark:bg-indigo-500/15 dark:text-indigo-300">
            <flux:icon.bolt class="size-3.5" /> NexBridge
        </span>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-4">
        {{-- Employee picker --}}
        <div class="lg:col-span-1">
            <div class="rounded-2xl border border-zinc-200/70 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search employee…" size="sm" />
                <div class="mt-2 max-h-[28rem] space-y-1 overflow-y-auto">
                    @forelse($employees as $emp)
                        <button wire:click="selectEmployee({{ $emp->id }})"
                            class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left transition {{ $employeeId === $emp->id ? 'bg-indigo-50 dark:bg-indigo-500/15' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-zinc-800 dark:text-zinc-100">{{ $emp->user?->name ?? 'Emp '.$emp->id }}</div>
                                <div class="truncate text-[11px] text-zinc-400">{{ $emp->user?->email ?? '—' }}</div>
                            </div>
                        </button>
                    @empty
                        <p class="px-2 py-6 text-center text-xs text-zinc-400">No employees match.</p>
                    @endforelse
                </div>
            </div>
        </div>

        {{-- Detail --}}
        <div class="space-y-4 lg:col-span-3">
            {{-- Filters --}}
            <div class="flex flex-wrap items-end gap-3 rounded-2xl border border-zinc-200/70 bg-white p-3 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                <flux:input wire:model="from" type="date" label="From" size="sm" class="w-40" />
                <flux:input wire:model="to" type="date" label="To" size="sm" class="w-40" />
                <div class="w-40">
                    <flux:select wire:model="status" label="Status" size="sm">
                        <flux:select.option value="">All</flux:select.option>
                        <flux:select.option value="approved">Approved</flux:select.option>
                        <flux:select.option value="pending">Pending</flux:select.option>
                        <flux:select.option value="rejected">Rejected</flux:select.option>
                    </flux:select>
                </div>
                <flux:button wire:click="fetch" variant="primary" icon="arrow-path" size="sm" wire:loading.attr="disabled">Load</flux:button>
                <div wire:loading wire:target="fetch,selectEmployee" class="text-xs text-zinc-400">Loading from Nexflow…</div>
            </div>

            @if($error)
                <div class="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-6 text-center text-sm text-amber-700 dark:border-amber-900/40 dark:bg-amber-900/15 dark:text-amber-300">
                    <flux:icon.exclamation-triangle class="mx-auto mb-1.5 size-6" /> {{ $error }}
                </div>
            @elseif(! $selected)
                <div class="rounded-2xl border border-dashed border-zinc-300 py-16 text-center dark:border-zinc-700">
                    <flux:icon.user class="mx-auto mb-2 size-8 text-zinc-300" />
                    <p class="text-sm text-zinc-400">Pick an employee to view their Nexflow overtime.</p>
                </div>
            @elseif($data)
                @php $s = $data['summary'] ?? []; $emp = $data['employee'] ?? []; @endphp
                {{-- Employee header + import all --}}
                <div class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    <div>
                        <div class="text-sm font-black text-zinc-900 dark:text-white">{{ $emp['name'] ?? $selected->user?->name }}</div>
                        <div class="text-[11px] text-zinc-400">{{ $emp['job_title'] ?? '—' }} · {{ $emp['email'] ?? $selected->user?->email }}
                            @if(($data['period']['from'] ?? null)) · {{ $data['period']['from'] }} → {{ $data['period']['to'] }}@endif
                        </div>
                    </div>
                    @if(($s['approved_count'] ?? 0) > 0)
                        <flux:button wire:click="importAllApproved" variant="primary" icon="arrow-down-tray" size="sm"
                            wire:confirm="Import all approved Nexflow overtime for this employee into payroll?">
                            Import approved ({{ $s['approved_count'] }})
                        </flux:button>
                    @endif
                </div>

                {{-- Summary cards --}}
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    @php
                        $cards = [
                            ['Approved OT', ($s['approved_ot_hours'] ?? 0).'h', 'text-emerald-600', $s['approved_count'] ?? 0],
                            ['Pending OT', ($s['pending_ot_hours'] ?? 0).'h', 'text-amber-600', $s['pending_count'] ?? 0],
                            ['Rejected', ($s['rejected_ot_hours'] ?? 0).'h', 'text-rose-500', $s['rejected_count'] ?? 0],
                            ['Total OT', ($s['total_ot_hours'] ?? 0).'h', 'text-zinc-700 dark:text-zinc-200', $s['total_ot_records'] ?? 0],
                        ];
                    @endphp
                    @foreach($cards as [$label, $val, $color, $count])
                        <div class="rounded-2xl border border-zinc-200/70 bg-white p-4 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                            <div class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $label }}</div>
                            <div class="mt-1 text-2xl font-black tabular-nums {{ $color }}">{{ $val }}</div>
                            <div class="text-[10px] text-zinc-400">{{ $count }} record(s)</div>
                        </div>
                    @endforeach
                </div>

                {{-- Records --}}
                <div class="overflow-hidden rounded-2xl border border-zinc-200/70 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
                    @forelse($data['ot_records'] ?? [] as $r)
                        @php
                            $rc = match($r['status'] ?? '') { 'approved' => 'bg-emerald-50 text-emerald-600', 'rejected' => 'bg-rose-50 text-rose-500', default => 'bg-amber-50 text-amber-600' };
                        @endphp
                        <div class="border-b border-zinc-100 p-4 last:border-0 dark:border-zinc-800">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <div class="flex items-center gap-2.5">
                                    <span class="text-sm font-black text-zinc-900 dark:text-white">{{ \Illuminate\Support\Carbon::parse($r['date'])->format('d M Y') }}</span>
                                    <span class="rounded-full px-2 py-0.5 text-[9px] font-bold uppercase {{ $rc }}">{{ $r['status'] ?? '—' }}</span>
                                    <span class="text-xs font-bold tabular-nums text-zinc-600 dark:text-zinc-300">{{ $r['ot_hours'] ?? 0 }}h</span>
                                </div>
                                @if(($r['status'] ?? '') === 'approved')
                                    <flux:button wire:click="importRecord({{ $r['id'] }})" size="xs" variant="primary" icon="arrow-down-tray">Import</flux:button>
                                @else
                                    <span class="text-[10px] italic text-zinc-400">{{ ($r['status'] ?? '') === 'pending' ? 'Not payable until L2 approves' : 'Not payable' }}</span>
                                @endif
                            </div>
                            @if($r['reason'] ?? null)<p class="mt-1 text-[11px] italic text-zinc-400">“{{ $r['reason'] }}”</p>@endif

                            {{-- Approval trail --}}
                            <div class="mt-2 flex flex-wrap gap-3 text-[11px]">
                                @foreach(['l1' => 'L1', 'l2' => 'L2'] as $lvl => $lbl)
                                    @php $a = $r['approvals'][$lvl] ?? null; @endphp
                                    @if($a)
                                        @php $ac = match($a['status'] ?? '') { 'approved' => 'text-emerald-600', 'rejected' => 'text-rose-500', default => 'text-amber-600' }; @endphp
                                        <span class="inline-flex items-center gap-1 rounded-lg bg-zinc-50 px-2 py-1 dark:bg-zinc-800">
                                            <span class="font-bold text-zinc-400">{{ $lbl }}</span>
                                            <span class="font-semibold {{ $ac }}">{{ ucfirst($a['status'] ?? '—') }}</span>
                                            @if($a['approver'] ?? null)<span class="text-zinc-400">· {{ $a['approver'] }}</span>@endif
                                        </span>
                                    @endif
                                @endforeach
                            </div>

                            {{-- Sessions --}}
                            @if($r['sessions'] ?? null)
                                <div class="mt-2 space-y-0.5">
                                    @foreach($r['sessions'] as $sess)
                                        <div class="flex items-center gap-2 text-[11px] text-zinc-500 dark:text-zinc-400">
                                            <flux:icon.clock class="size-3 text-zinc-300" />
                                            <span class="font-mono tabular-nums">{{ $sess['started_at'] ?? '—' }} → {{ $sess['stopped_at'] ?? '—' }}</span>
                                            <span class="text-zinc-400">· {{ $sess['duration_minutes'] ?? 0 }}m</span>
                                            @if($sess['task'] ?? null)<span class="truncate text-zinc-400">· {{ $sess['task'] }}</span>@endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @empty
                        <p class="py-12 text-center text-sm text-zinc-400">No overtime records for this period.</p>
                    @endforelse
                </div>
            @endif
        </div>
    </div>
</flux:main>
