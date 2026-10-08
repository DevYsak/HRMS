{{--
    Today: the punch timeline (left) and the day's totals (right) in one card —
    replaces Attendance Journey, Session Summary, Working Hours Breakdown,
    Shift Progress and Biometric Status. The card shows the latest punches
    only; the full timeline, sessions, raw scans and the device open in a
    right-side drawer ("View all punches" / "View raw punches").
--}}
@php
    $otMinutes = (int) ($todayCalc['approved_ot_minutes'] ?? 0);
    $statusChip = match (true) {
        $isLive => ['Working', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        $missingOut => ['Missing checkout', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'],
        (bool) $lastOut => ['Completed', 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300'],
        default => [match ($todayRow['status'] ?? null) { null, 'Today' => 'Not clocked in', default => $todayRow['status'] }, 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300'],
    };
    // Preview: the latest punches only (the current one matters most; First In is a KPI card).
    $nodes = $pj['nodes'] ?? [];
    $previewLimit = 6;
    $hiddenCount = max(0, count($nodes) - $previewLimit);
    $hasDrawer = $nodes !== [] || (int) ($pj['raw_count'] ?? 0) > 0;
    // Same reading as the former Biometric Status card: the device's last sync,
    // and the serial the engine stamped on today's summary first.
    $online = $biometricDevice?->last_synced_at;
    $isOnline = $online && \Carbon\Carbon::parse($online)->gt(now()->subMinutes(30));
    $deviceSerial = $todaySummary?->device_serial
        ?: collect($syncHistory)->pluck('serial')->filter()->first()
        ?: $biometricDevice?->serial_number;
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="today-title" data-today>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-zinc-100 px-5 py-4 dark:border-zinc-800">
        <div class="flex items-center gap-3">
            <h2 id="today-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Today</h2>
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ now()->format('D, d M') }}</span>
            <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold {{ $statusChip[1] }}">{{ $statusChip[0] }}</span>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            @if(! $todayAttendance)
                <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'in' })"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600">
                    <flux:icon.arrow-right-end-on-rectangle class="size-4" /> Clock In</button>
            @elseif($isIn)
                <button type="button" @click="$flux.modal('punch-capture').show(); $dispatch('open-punch', { action: 'out' })"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-orange-500 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-orange-600">
                    <flux:icon.arrow-left-start-on-rectangle class="size-4" /> Clock Out</button>
                @if($activeBreak)
                    <button type="button" wire:click="endBreak" class="rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">End break</button>
                @else
                    <button type="button" wire:click="startBreak" class="rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">Start break</button>
                @endif
            @endif
            <button type="button" wire:click="openRegularisation('{{ today()->toDateString() }}')"
                class="rounded-xl border border-zinc-200 px-3 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">Regularize</button>
        </div>
    </div>

    <div class="grid gap-0 md:grid-cols-[minmax(0,1fr)_minmax(0,18rem)]">
        {{-- Punch timeline --}}
        <div class="p-5">
            @if($nodes !== [])
                @if($hiddenCount > 0)
                    <p class="mb-3 text-xs text-zinc-500 dark:text-zinc-400">{{ $hiddenCount }} earlier {{ \Illuminate\Support\Str::plural('punch', $hiddenCount) }} not shown</p>
                @endif
                @include('attendance.my.punch-list', ['nodes' => array_slice($nodes, -$previewLimit)])
            @elseif($todayAttendance?->check_in)
                {{-- Web/mobile punch with no biometric events --}}
                <ol class="space-y-3 text-sm" data-punch-timeline>
                    <li class="flex gap-4"><span class="w-20 font-semibold tabular-nums">{{ $todayAttendance->check_in->format('h:i A') }}</span><span class="text-emerald-700 dark:text-emerald-400">{{ ucfirst((string) ($todayAttendance->check_in_method ?? 'web')) }} • IN</span></li>
                    @if($todayAttendance->check_out)
                        <li class="flex gap-4"><span class="w-20 font-semibold tabular-nums">{{ $todayAttendance->check_out->format('h:i A') }}</span><span class="text-zinc-600 dark:text-zinc-300">{{ ucfirst((string) ($todayAttendance->check_out_method ?? 'web')) }} • OUT</span></li>
                    @endif
                </ol>
            @else
                <p class="py-6 text-center text-sm text-zinc-500 dark:text-zinc-400">No punches recorded today.</p>
            @endif
        </div>

        {{-- Totals --}}
        <dl class="grid grid-cols-2 gap-x-4 gap-y-3 border-t border-zinc-100 p-5 text-sm md:grid-cols-1 md:border-l md:border-t-0 dark:border-zinc-800">
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Worked</dt><dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $hm($workedMin) }}</dd></div>
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Break</dt><dd @class(['font-semibold tabular-nums', 'text-amber-600' => (bool) ($todayCalc['excess_break'] ?? false), 'text-zinc-900 dark:text-white' => ! ($todayCalc['excess_break'] ?? false)])>{{ $hm($breakMin) }}</dd></div>
            <div class="col-span-2 flex items-baseline justify-between gap-2 md:col-span-1"><dt class="text-zinc-500 dark:text-zinc-400">Shift</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $shiftWindow ?? 'Shift not assigned' }}</dd></div>
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">Late</dt><dd @class(['font-semibold', 'text-amber-600' => $isLate, 'text-zinc-900 dark:text-white' => ! $isLate])>{{ $firstIn ? ($isLate ? 'Yes'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'No') : '—' }}</dd></div>
            <div class="flex items-baseline justify-between gap-2"><dt class="text-zinc-500 dark:text-zinc-400">OT</dt><dd class="font-semibold tabular-nums text-zinc-900 dark:text-white">{{ $otMinutes > 0 ? $hm($otMinutes).' approved' : '0h' }}</dd></div>
            <p class="col-span-2 text-[11px] leading-snug text-zinc-400 md:col-span-1">Worked = last OUT − first IN. Breaks are shown for information and never deducted.</p>
        </dl>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 px-5 py-3 dark:border-zinc-800">
        @if($issueCount === 0)
            <span class="inline-flex items-center gap-1.5 text-xs font-medium text-emerald-700 dark:text-emerald-400"><flux:icon.check-circle class="size-4" /> No attendance issues today</span>
        @else
            <a href="#attention" class="inline-flex items-center gap-1.5 text-xs font-semibold text-amber-700 dark:text-amber-400"><flux:icon.exclamation-triangle class="size-4" /> {{ $issueCount }} {{ \Illuminate\Support\Str::plural('item', $issueCount) }} need attention</a>
        @endif

        @if($hasDrawer || $deviceSerial || $biometricDevice)
            <div class="flex items-center gap-4 text-xs font-semibold">
                @if($hiddenCount > 0)
                    <button type="button" @click="$flux.modal('today-punches').show()" class="text-orange-600 hover:text-orange-700">View all {{ count($nodes) }} punches</button>
                @endif
                <button type="button" @click="$flux.modal('today-punches').show()" class="text-zinc-600 hover:text-orange-600 dark:text-zinc-300">View raw punches</button>
            </div>
        @endif
    </div>
</section>

{{-- Right-side drawer: the whole day — full timeline, sessions, raw scans, device. --}}
@if($hasDrawer || $deviceSerial || $biometricDevice)
<flux:modal name="today-punches" flyout class="w-full md:w-[34rem]" data-raw-punches>
    <div class="space-y-6">
        <div>
            <flux:heading size="lg">Today's punches</flux:heading>
            <flux:subheading>{{ now()->format('l, d F Y') }} · {{ count($nodes) }} on the timeline · {{ (int) ($pj['raw_count'] ?? 0) }} raw {{ \Illuminate\Support\Str::plural('scan', (int) ($pj['raw_count'] ?? 0)) }}</flux:subheading>
        </div>

        @if($nodes !== [])
            <section>
                <h3 class="mb-3 text-sm font-semibold text-zinc-900 dark:text-white">Timeline</h3>
                @include('attendance.my.punch-list', ['nodes' => $nodes])
            </section>
        @endif

        @if(! empty($pj['sessions']))
            <section>
                <h3 class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">Sessions:</h3>
                <ul class="space-y-1 text-sm tabular-nums text-zinc-700 dark:text-zinc-300">
                    @foreach($pj['sessions'] as $session)
                        <li>{{ $session['in'] ?? '—' }} → {{ $session['out'] ?? ($session['live'] ? 'now' : '—') }} <span class="text-zinc-400">({{ $session['label'] }})</span></li>
                    @endforeach
                </ul>
            </section>
        @endif

        @if(! empty($pj['raw_events']))
            <section>
                <h3 class="mb-2 text-sm font-semibold text-zinc-900 dark:text-white">Raw punches</h3>
                <div class="max-h-[45vh] overflow-y-auto rounded-xl border border-zinc-100 dark:border-zinc-800">
                    <table class="w-full text-xs">
                        <thead class="sticky top-0 bg-zinc-50 text-left text-[11px] text-zinc-500 dark:bg-zinc-800"><tr><th class="px-3 py-2 font-medium">Time</th><th class="px-2 font-medium">Direction</th><th class="px-2 font-medium">Method</th><th class="px-2 font-medium">Device</th><th class="px-2 font-medium">Used</th></tr></thead>
                        <tbody class="divide-y divide-zinc-100 text-zinc-700 dark:divide-zinc-800 dark:text-zinc-300">
                            @foreach($pj['raw_events'] as $event)
                                <tr>
                                    <td class="px-3 py-1.5 tabular-nums">{{ $event['time'] }}</td>
                                    <td class="px-2">{{ strtoupper((string) ($event['direction'] ?? '—')) }}</td>
                                    <td class="px-2">{{ $event['method'] ?? '—' }}</td>
                                    <td class="px-2">{{ $event['device'] ?? '—' }}</td>
                                    <td class="px-2">{{ ($event['flag'] ?? 'kept') === 'kept' ? 'Yes' : ucfirst((string) $event['flag']).($event['note'] ? ' — '.$event['note'] : '') }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        @if($biometricDevice || $deviceSerial)
            <section class="text-xs text-zinc-500">
                Device: <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $biometricDevice?->name ?? '—' }}</span>
                @if($deviceSerial) · Serial {{ $deviceSerial }} @endif
                · <span @class(['font-semibold', 'text-emerald-600' => $isOnline, 'text-amber-600' => ! $isOnline])>{{ $isOnline ? 'Online' : ($online ? 'Delayed' : 'Never synced') }}</span>
                @if($online) · last sync {{ \Carbon\Carbon::parse($online)->format('d M, h:i A') }} @endif
            </section>
        @endif
    </div>
</flux:modal>
@endif
