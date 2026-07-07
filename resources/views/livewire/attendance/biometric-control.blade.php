<flux:main class="min-h-screen bg-[#FFF8F3] dark:bg-white/5 p-4 md:p-6">

{{-- ═══════════════ HEADER ═══════════════ --}}
<div class="mb-4 flex flex-wrap items-center justify-between gap-3">
    <div>
        <h1 class="text-2xl font-black tracking-tight text-zinc-900 dark:text-white">Biometric Control Center</h1>
        <div class="mt-0.5 flex items-center gap-1.5 text-xs text-zinc-400">
            <span>Dashboard</span><flux:icon.chevron-right class="size-3" /><span>Attendance</span><flux:icon.chevron-right class="size-3" /><span class="font-semibold text-orange-500">Biometric</span>
        </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
        <div class="flex items-center gap-1 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 p-1 shadow-sm">
            <button wire:click="previousDay" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-left class="size-4" /></button>
            <button wire:click="today" class="min-w-[5rem] px-2 py-1 text-center text-xs font-bold text-zinc-700 dark:text-zinc-200 transition hover:text-orange-500">{{ $dateLabel }}</button>
            <button wire:click="nextDay" class="flex size-7 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-orange-50 hover:text-orange-500"><flux:icon.chevron-right class="size-4" /></button>
        </div>
        <button wire:click="syncNow" wire:loading.attr="disabled" class="inline-flex items-center gap-1.5 rounded-xl bg-gradient-to-r from-orange-500 to-amber-500 px-4 py-2 text-xs font-bold text-white shadow-lg shadow-orange-300/40 transition hover:shadow-xl disabled:opacity-60">
            <flux:icon.arrow-path class="size-4" wire:loading.class="animate-spin" wire:target="syncNow" /> Sync Machine
        </button>
        <button wire:click="exportLogs" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.arrow-down-tray class="size-4 text-orange-500" /> Export Logs</button>
    </div>
</div>

{{-- ═══════════════ OFFLINE ALERT ═══════════════ --}}
@unless($anyOnline)
    <div class="mb-4 flex items-center gap-3 rounded-[18px] border border-rose-300 bg-rose-50 p-4 shadow-sm">
        <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl bg-rose-500 text-white shadow-lg shadow-rose-200"><flux:icon.exclamation-triangle class="size-5" /></span>
        <div>
            <div class="text-sm font-black text-rose-900">No biometric device has synced in the last 30 minutes</div>
            <div class="text-xs text-rose-700">Punches may be delayed. Try "Sync Machine", or check the engine service on the server.</div>
        </div>
    </div>
@endunless

{{-- ═══════════════ TODAY VOLUME + METHOD MIX ═══════════════ --}}
<div class="mb-4 grid grid-cols-1 gap-4 lg:grid-cols-12">
    {{-- Volume tiles --}}
    <div class="grid grid-cols-2 gap-3 lg:col-span-4">
        @php
            $vol = [
                ["Today's Punches", $totalPunches, 'finger-print', '#F97316'],
                ['Employees Synced', $syncedEmployees, 'users', '#10b981'],
                ['Machines', count($devices) + count($discovered), 'cpu-chip', '#6366f1'],
                ['Successful', $totalPunches, 'check-circle', '#10b981'],
            ];
        @endphp
        @foreach($vol as [$label, $value, $icon, $color])
            <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm">
                <span class="inline-flex size-8 items-center justify-center rounded-xl" style="background: {{ $color }}1a; color: {{ $color }};"><flux:icon :icon="$icon" class="size-4" /></span>
                <div class="mt-2 text-xl font-black tabular-nums text-zinc-900 dark:text-white">{{ $value }}</div>
                <div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">{{ $label }}</div>
            </div>
        @endforeach
    </div>

    {{-- Method mix --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-8">
        <div class="mb-3 flex items-center justify-between">
            <div class="text-sm font-black text-zinc-900 dark:text-white">Verification Method Mix</div>
            <span class="text-[10px] font-bold uppercase tracking-widest text-zinc-400">{{ $dateLabel }} · {{ $totalPunches }} punches</span>
        </div>
        <div class="space-y-3">
            @foreach($methods as $m)
                <div>
                    <div class="mb-1 flex items-center justify-between text-xs">
                        <span class="font-bold text-zinc-700 dark:text-zinc-200">{{ $m['label'] }} <span class="text-zinc-400">· {{ $m['count'] }}</span></span>
                        <span class="font-black tabular-nums" style="color: {{ $m['color'] }}">{{ $m['pct'] }}%</span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                        <div class="h-full rounded-full transition-all duration-700" style="width: {{ $m['pct'] }}%; background: {{ $m['color'] }};"></div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</div>

{{-- ═══════════════ MACHINES ═══════════════ --}}
<div class="mb-4">
    <div class="mb-2 text-sm font-black text-zinc-900 dark:text-white">All Machines</div>
    @if(count($devices) > 0 || count($discovered) > 0)
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
            @foreach($devices as $d)
                @php
                    $tone = $d['tone'];
                    $badge = ['emerald' => 'bg-emerald-100 text-emerald-700', 'amber' => 'bg-amber-100 text-amber-700', 'rose' => 'bg-rose-100 text-rose-600'][$tone];
                    $dot = ['emerald' => 'bg-emerald-500 animate-pulse', 'amber' => 'bg-amber-500 animate-pulse', 'rose' => 'bg-rose-500'][$tone];
                @endphp
                <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-4 shadow-sm transition hover:shadow-md">
                    <div class="mb-3 flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-orange-50 to-white"><flux:icon.finger-print class="size-6 text-orange-400" /></div>
                            <div class="min-w-0">
                                <div class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $d['name'] }}</div>
                                <div class="truncate text-[10px] text-zinc-400">{{ $d['address'] }}</div>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $badge }}"><span class="size-1.5 rounded-full {{ $dot }}"></span>{{ ucfirst($d['state']) }}</span>
                    </div>
                    <div class="grid grid-cols-2 gap-1.5 text-xs">
                        @foreach([
                            ['Health', $d['health']],
                            ['Last Sync', $d['last_sync_human']],
                            ['Sync Count', $d['sync_count']],
                            ['Connection', ucfirst($d['ping_status'])],
                        ] as [$k, $v])
                            <div class="flex items-center justify-between rounded-lg bg-zinc-50/70 px-2.5 py-1.5"><span class="text-zinc-400">{{ $k }}</span><span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $v }}</span></div>
                        @endforeach
                    </div>
                    @if($d['ping_error'])
                        <div class="mt-2 truncate rounded-lg bg-rose-50 px-2.5 py-1 text-[10px] text-rose-600" title="{{ $d['ping_error'] }}">{{ $d['ping_error'] }}</div>
                    @endif
                    <div class="mt-3 flex gap-1.5">
                        <button wire:click="syncNow" class="flex-1 inline-flex items-center justify-center gap-1 rounded-lg bg-orange-500 px-2.5 py-1.5 text-[11px] font-bold text-white transition hover:bg-orange-600"><flux:icon.arrow-path class="size-3" wire:loading.class="animate-spin" wire:target="syncNow" /> Sync</button>
                        <span class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 dark:border-zinc-800 px-2.5 py-1.5 text-[11px] font-bold text-zinc-300" title="Remote restart is not supported by this device (pull-only engine)."><flux:icon.power class="size-3" /> Restart</span>
                    </div>
                </div>
            @endforeach

            {{-- Discovered serials not in the registered list --}}
            @foreach($discovered as $disc)
                <div class="rounded-[18px] border border-dashed border-orange-200 bg-orange-50/30 p-4 shadow-sm">
                    <div class="mb-3 flex items-center gap-3">
                        <div class="grid size-11 shrink-0 place-items-center rounded-xl bg-white dark:bg-zinc-900"><flux:icon.cpu-chip class="size-6 text-orange-400" /></div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-black text-zinc-900 dark:text-white">{{ $disc['serial'] }}</div>
                            <div class="text-[10px] text-zinc-400">Discovered from punches</div>
                        </div>
                        <span class="ml-auto inline-flex items-center gap-1.5 rounded-full bg-emerald-100 px-2 py-0.5 text-[10px] font-bold text-emerald-700"><span class="size-1.5 animate-pulse rounded-full bg-emerald-500"></span>Reporting</span>
                    </div>
                    <div class="rounded-lg bg-white dark:bg-zinc-900 px-3 py-2 text-xs"><span class="text-zinc-400">Punches ({{ $dateLabel }})</span> <span class="float-right font-black text-zinc-900 dark:text-white">{{ $disc['punches'] }}</span></div>
                    <p class="mt-2 text-[10px] text-zinc-400">Serial seen in the engine feed. Add it under device settings to name and monitor it.</p>
                </div>
            @endforeach
        </div>
    @else
        <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 py-12 text-center text-sm text-zinc-400 shadow-sm">
            <flux:icon.cpu-chip class="mx-auto mb-2 size-9 opacity-30" /> No machines registered or reporting yet.
        </div>
    @endif
</div>

{{-- ═══════════════ QUEUE WORKER HEALTH ═══════════════ --}}
@php
    [$qBadge, $qDot, $qLabel] = $queueOnline
        ? ['bg-emerald-100 text-emerald-700', 'bg-emerald-500 animate-pulse', 'Online']
        : ['bg-rose-100 text-rose-600', 'bg-rose-500', 'Offline'];
@endphp
<div class="mb-4 rounded-[18px] border {{ $queueOnline ? 'border-orange-100/70 bg-white dark:bg-zinc-900' : 'border-rose-300 bg-rose-50/40' }} p-4 shadow-sm">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex items-center gap-3">
            <span class="inline-flex size-10 shrink-0 items-center justify-center rounded-xl {{ $queueOnline ? 'bg-orange-50 text-orange-500' : 'bg-rose-100 text-rose-500' }}"><flux:icon.cog-6-tooth class="size-5" wire:loading.class="animate-spin" wire:target="$refresh" /></span>
            <div>
                <div class="flex items-center gap-2">
                    <span class="text-sm font-black text-zinc-900 dark:text-white">Queue Worker</span>
                    <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[10px] font-bold {{ $qBadge }}"><span class="size-1.5 rounded-full {{ $qDot }}"></span>{{ $qLabel }}</span>
                </div>
                <div class="text-[11px] text-zinc-500 dark:text-zinc-400">
                    @if($queueOnline)
                        Last heartbeat {{ $queueHeartbeat }} · emails are being delivered in the background
                    @else
                        No heartbeat in the last 3 minutes — queued emails are not being sent. Start the worker on the server.
                    @endif
                </div>
            </div>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-center"><div class="text-lg font-black tabular-nums {{ $queuePending > 20 ? 'text-amber-600' : 'text-zinc-900 dark:text-white' }}">{{ $queuePending }}</div><div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">Pending</div></div>
            <div class="text-center"><div class="text-lg font-black tabular-nums {{ $queueFailed > 0 ? 'text-rose-500' : 'text-zinc-900 dark:text-white' }}">{{ $queueFailed }}</div><div class="text-[9px] font-bold uppercase tracking-wide text-zinc-400">Failed</div></div>
            <button wire:click="$refresh" class="inline-flex items-center gap-1.5 rounded-xl border border-orange-100 bg-white dark:bg-zinc-900 px-3 py-2 text-xs font-bold text-zinc-600 dark:text-zinc-300 shadow-sm transition hover:bg-orange-50"><flux:icon.arrow-path class="size-4 text-orange-500" wire:loading.class="animate-spin" wire:target="$refresh" /> Refresh</button>
        </div>
    </div>
</div>

{{-- ═══════════════ SYNC LOGS + CAPABILITY NOTE ═══════════════ --}}
<div class="grid grid-cols-1 gap-4 lg:grid-cols-12">
    <div class="overflow-hidden rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 shadow-sm lg:col-span-8">
        <div class="flex items-center justify-between border-b border-orange-100/70 px-5 py-3">
            <h3 class="flex items-center gap-2 text-sm font-black text-zinc-900 dark:text-white"><flux:icon.queue-list class="size-4 text-orange-500" /> Sync Logs</h3>
            <button wire:click="exportLogs" class="text-[11px] font-bold text-orange-500 hover:underline">Export</button>
        </div>
        @if(count($logs) > 0)
            <div class="divide-y divide-orange-50">
                @foreach($logs as $log)
                    <div class="flex items-center justify-between px-5 py-2 text-xs">
                        <span class="font-bold text-zinc-800 dark:text-zinc-100">{{ $log['employee'] }}</span>
                        <span class="text-zinc-400">{{ $log['date'] }} · {{ $log['punches'] }} punches</span>
                        <span class="text-zinc-400">{{ $log['device'] }}</span>
                        <span class="inline-flex items-center gap-1 font-semibold text-emerald-600"><flux:icon.check class="size-3" /> {{ $log['synced'] }}</span>
                    </div>
                @endforeach
            </div>
        @else
            <div class="py-10 text-center text-sm text-zinc-400">No sync activity recorded.</div>
        @endif
    </div>

    {{-- Capability note (honesty) --}}
    <div class="rounded-[18px] border border-orange-100/70 bg-white dark:bg-zinc-900 p-5 shadow-sm lg:col-span-4">
        <div class="mb-2 flex items-center gap-2"><flux:icon.information-circle class="size-4 text-orange-500" /><div class="text-sm font-black text-zinc-900 dark:text-white">Device Capabilities</div></div>
        <p class="mb-3 text-[11px] text-zinc-500 dark:text-zinc-400">This deployment pulls punches from the attendance engine. The following are not reported by the device and are intentionally omitted rather than faked:</p>
        <div class="space-y-1.5">
            @foreach(['Firmware version', 'Battery level', 'Network signal', 'Failed-punch count', 'Remote restart'] as $cap)
                <div class="flex items-center gap-2 rounded-lg bg-zinc-50/70 px-2.5 py-1.5 text-[11px] text-zinc-500 dark:text-zinc-400"><flux:icon.minus-circle class="size-3.5 text-zinc-300" /> {{ $cap }}</div>
            @endforeach
        </div>
        <p class="mt-3 text-[10px] text-zinc-400">Available in real time: connection state, last sync, punch volume and verification method mix.</p>
    </div>
</div>

</flux:main>
