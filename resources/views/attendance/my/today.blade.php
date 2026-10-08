{{--
    Today: the day's punches as a table (left) and Today's Summary (right) in
    one card — replaces Attendance Journey, Session Summary, Working Hours
    Breakdown, Shift Progress and Biometric Status. Each punch's status comes
    from the PunchClassifier journey (break out / break in / present); missing
    punches come from the PunchTimeline engine. The card lists up to 15
    punches; the full day, sessions, raw scans and the device open in a
    right-side drawer ("View all punches" / "View raw punches").
--}}
@php
    $otMinutes = (int) ($todayCalc['approved_ot_minutes'] ?? 0);
    $statusChip = match (true) {
        $isLive => ['Working', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        $missingOut => ['Missing checkout', 'bg-red-50 text-red-700 dark:bg-red-500/10 dark:text-red-400'],
        (bool) $lastOut => ['Completed', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        default => [match ($todayRow['status'] ?? null) { null, 'Today' => 'Not clocked in', default => $todayRow['status'] }, 'bg-zinc-100 text-zinc-600 dark:bg-white/5 dark:text-zinc-300'],
    };
    // Same reading as the former Biometric Status card: the device's last sync,
    // and the serial the engine stamped on today's summary first.
    $online = $biometricDevice?->last_synced_at;
    $isOnline = $online && \Carbon\Carbon::parse($online)->gt(now()->subMinutes(30));
    $deviceSerial = $todaySummary?->device_serial
        ?: collect($syncHistory)->pluck('serial')->filter()->first()
        ?: $biometricDevice?->serial_number;

    // Punches: the card lists up to 15 (the latest), the drawer has the rest.
    $nodes = $pj['nodes'] ?? [];
    $previewLimit = 15;
    $hiddenCount = max(0, count($nodes) - $previewLimit);
    $hasDrawer = $nodes !== [] || (int) ($pj['raw_count'] ?? 0) > 0;

    // Each kept punch's meaning from the classified journey, matched by time.
    $journeyByTime = collect($attendanceJourney)->groupBy('time')->map(fn ($g) => $g->pluck('type')->all())->all();
    $rows = [];
    $shown = array_values(array_slice($nodes, -$previewLimit));
    $prevWasBreak = false;
    foreach ($shown as $i => $node) {
        $isMissing = $node['type'] === 'missing';
        $kind = ! $isMissing && ! empty($journeyByTime[$node['time']]) ? array_shift($journeyByTime[$node['time']]) : null;
        // The classifier's rule — every OUT before the departure opens a break —
        // also holds while a session is still open: an OUT with a later IN is a
        // break, and the IN after it a return.
        $laterIn = collect(array_slice($shown, $i + 1))->contains(fn ($n) => $n['type'] !== 'missing' && $n['dir'] === 'IN');
        if ($kind === 'out' && $laterIn) {
            $kind = 'break';
        } elseif ($kind === 'in' && $prevWasBreak) {
            $kind = 'resume';
        }
        $prevWasBreak = $kind === 'break';
        $isReg = ($node['source'] ?? '') === 'regularisation';
        [$statusLabel, $statusClass] = match (true) {
            $isMissing => ['Missing '.$node['dir'], 'bg-red-50 text-red-600 dark:bg-red-500/10 dark:text-red-400'],
            $isReg => ['Regularised', 'bg-violet-50 text-violet-700 dark:bg-violet-500/10 dark:text-violet-300'],
            $node['type'] === 'live' && $isLive => ['Working', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
            $kind === 'break' => ['Break Out', 'bg-orange-50 text-orange-700 dark:bg-orange-500/10 dark:text-orange-400'],
            $kind === 'resume' => ['Break In', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
            default => ['Present', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'],
        };
        $rows[] = [
            'time' => $node['time'],
            'type' => $isMissing ? '—' : $node['dir'],
            'source' => $isMissing ? 'System' : ($node['method_label'] ?? match ($node['source'] ?? '') { 'web' => 'Web', 'regularisation' => 'Regularisation', default => '—' }),
            'status' => $statusLabel,
            'status_class' => $statusClass,
            'dot' => match (true) { $isMissing => 'bg-red-500', $isReg => 'bg-violet-500', $node['dir'] === 'IN' => 'bg-emerald-500', default => 'bg-red-400' },
            'live' => $node['type'] === 'live' && $isLive,
            'regularised' => $isReg,
        ];
    }

    $summaryRow = 'flex items-baseline justify-between gap-3 border-b border-orange-100/80 py-3.5 text-sm last:border-b-0 dark:border-white/5';
    $breakOver = (bool) ($todayCalc['excess_break'] ?? false) || ($breakAllowance > 0 && $breakMin > $breakAllowance);
@endphp

<section class="rounded-2xl border border-zinc-200/80 bg-white shadow-sm dark:border-zinc-800 dark:bg-zinc-900" aria-labelledby="today-title" data-today>
    <div class="flex flex-wrap items-center justify-between gap-3 px-5 pb-3 pt-5">
        <div class="flex items-center gap-3">
            <flux:icon.clipboard-document-list class="size-6 text-orange-500" />
            <h2 id="today-title" class="text-[17px] font-semibold text-zinc-900 dark:text-white">Today</h2>
            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ now()->format('D, d M') }}</span>
            <span class="rounded-full px-2.5 py-0.5 text-[11px] font-semibold {{ $statusChip[1] }}">{{ $statusChip[0] }}</span>
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
                    <button type="button" wire:click="endBreak" class="rounded-xl border border-zinc-200 px-3.5 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">End break</button>
                @else
                    <button type="button" wire:click="startBreak" class="rounded-xl border border-zinc-200 px-3.5 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">Start break</button>
                @endif
            @endif
            <button type="button" wire:click="openRegularisation('{{ today()->toDateString() }}')"
                class="inline-flex items-center gap-1.5 rounded-xl border border-zinc-200 px-3.5 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-200 dark:hover:bg-white/5">
                <flux:icon.cog-6-tooth class="size-4" /> Regularize</button>
        </div>
    </div>

    <div class="grid gap-5 px-5 pb-5 lg:grid-cols-[minmax(0,1fr)_minmax(0,22rem)]">
        {{-- Punch table --}}
        <div class="min-w-0">
            @if($rows !== [])
                @if($hiddenCount > 0)
                    <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">{{ $hiddenCount }} earlier {{ \Illuminate\Support\Str::plural('punch', $hiddenCount) }} not shown</p>
                @endif
                <div class="relative overflow-x-auto">
                    <table class="w-full text-sm" data-punch-table>
                        <thead>
                            <tr class="border-b border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800 dark:text-zinc-400">
                                <th class="py-2 pl-7 font-medium">Time</th>
                                <th class="py-2 font-medium">Punch Type</th>
                                <th class="hidden py-2 font-medium sm:table-cell">Source</th>
                                <th class="py-2 font-medium">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($rows as $row)
                                <tr @if($row['regularised']) data-regularised @endif>
                                    <td class="py-2 pl-1.5 font-semibold tabular-nums text-zinc-900 dark:text-white">
                                        <span class="inline-flex items-center gap-3"><span class="size-2.5 shrink-0 rounded-full {{ $row['dot'] }} @if($row['live']) animate-pulse @endif"></span>{{ $row['time'] }}</span>
                                    </td>
                                    <td class="py-2 text-zinc-700 dark:text-zinc-300">{{ $row['type'] }}</td>
                                    <td class="hidden py-2 text-zinc-500 sm:table-cell dark:text-zinc-400">{{ $row['source'] }}</td>
                                    <td class="py-2"><span class="whitespace-nowrap rounded-md px-2 py-0.5 text-[11px] font-semibold {{ $row['status_class'] }}">{{ $row['status'] }}</span></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @elseif($todayAttendance?->check_in)
                {{-- Web/mobile punch with no biometric events --}}
                <table class="w-full text-sm" data-punch-table>
                    <thead><tr class="border-b border-zinc-100 text-left text-xs text-zinc-500 dark:border-zinc-800"><th class="py-2 font-medium">Time</th><th class="py-2 font-medium">Punch Type</th><th class="py-2 font-medium">Source</th></tr></thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        <tr><td class="py-2 font-semibold tabular-nums">{{ $todayAttendance->check_in->format('h:i A') }}</td><td class="py-2">IN</td><td class="py-2 text-zinc-500">{{ ucfirst((string) ($todayAttendance->check_in_method ?? 'web')) }}</td></tr>
                        @if($todayAttendance->check_out)
                            <tr><td class="py-2 font-semibold tabular-nums">{{ $todayAttendance->check_out->format('h:i A') }}</td><td class="py-2">OUT</td><td class="py-2 text-zinc-500">{{ ucfirst((string) ($todayAttendance->check_out_method ?? 'web')) }}</td></tr>
                        @endif
                    </tbody>
                </table>
            @else
                <p class="py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">No punches recorded today.</p>
            @endif
        </div>

        {{-- Today's Summary --}}
        <aside class="rounded-2xl bg-orange-50/50 p-5 dark:bg-white/5" aria-labelledby="today-summary-title">
            <h3 id="today-summary-title" class="mb-2 flex items-center gap-2 text-sm font-semibold text-zinc-900 dark:text-white"><flux:icon.clock class="size-5 text-orange-500" /> Today's Summary</h3>
            <dl>
                <div class="{{ $summaryRow }}"><dt class="text-zinc-600 dark:text-zinc-400">Worked</dt><dd class="text-base font-bold tabular-nums text-zinc-900 dark:text-white">{{ $hm($workedMin) }}</dd></div>
                <div class="{{ $summaryRow }}"><dt class="text-zinc-600 dark:text-zinc-400">Break</dt><dd @class(['text-base font-bold tabular-nums', 'text-orange-600' => $breakOver, 'text-zinc-900 dark:text-white' => ! $breakOver])>{{ $hm($breakMin) }}</dd></div>
                <div class="{{ $summaryRow }}"><dt class="text-zinc-600 dark:text-zinc-400">Shift</dt><dd class="font-semibold text-zinc-900 dark:text-white">{{ $shiftWindow ?? 'Shift not assigned' }}</dd></div>
                <div class="{{ $summaryRow }}"><dt class="text-zinc-600 dark:text-zinc-400">Late</dt><dd @class(['font-bold', 'text-orange-600' => $isLate, 'text-emerald-600' => $firstIn && ! $isLate, 'text-zinc-400' => ! $firstIn])>{{ $firstIn ? ($isLate ? 'Yes'.($lateMinutes > 0 ? ' · '.$lateMinutes.'m' : '') : 'No') : '—' }}</dd></div>
                <div class="{{ $summaryRow }}"><dt class="text-zinc-600 dark:text-zinc-400">OT</dt><dd class="font-bold tabular-nums text-zinc-900 dark:text-white">{{ $otMinutes > 0 ? $hm($otMinutes).' approved' : '0h' }}</dd></div>
            </dl>
            <p class="mt-3 flex gap-2 rounded-xl bg-zinc-100/80 p-3 text-[11px] leading-snug text-zinc-500 dark:bg-white/5 dark:text-zinc-400">
                <flux:icon.information-circle class="size-4 shrink-0 text-zinc-500" />
                <span>Worked = last OUT − first IN. Breaks are shown for information and never deducted.</span>
            </p>
        </aside>
    </div>

    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 px-5 py-3.5 dark:border-zinc-800">
        @if($issueCount === 0)
            <span class="inline-flex items-center gap-1.5 text-sm font-medium text-emerald-700 dark:text-emerald-400"><flux:icon.check-circle class="size-4" /> No attendance issues today</span>
        @else
            <a href="#attention" class="inline-flex items-center gap-1.5 text-sm font-semibold text-orange-600 dark:text-orange-400"><flux:icon.exclamation-triangle class="size-4" /> {{ $issueCount }} {{ \Illuminate\Support\Str::plural('item', $issueCount) }} {{ $issueCount === 1 ? 'needs' : 'need' }} attention</a>
        @endif

        @if($hasDrawer || $deviceSerial || $biometricDevice)
            <div class="flex items-center gap-4 text-sm font-semibold">
                @if($hiddenCount > 0)
                    <button type="button" @click="$flux.modal('today-punches').show()" class="text-orange-600 hover:text-orange-700">View all {{ count($nodes) }} punches</button>
                @endif
                <button type="button" @click="$flux.modal('today-punches').show()" class="inline-flex items-center gap-1 text-zinc-700 hover:text-orange-600 dark:text-zinc-300">View raw punches <flux:icon.chevron-right class="size-4" /></button>
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
